<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Jobs;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;

final class ProcessConversionsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Identifiers rather than a serialized model: the row may change or vanish
     * between dispatch and execution, and handle() copes with both.
     *
     * $dispatchedPath is the primary this job was dispatched to convert.
     * handle() compares it against the row's current value: a second upload
     * landing before this job runs already dispatched its own job for the
     * new primary, so re-deriving from a column that has since moved on would
     * only redo that job's work.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int|string $modelKey,
        public readonly string $field,
        public readonly string $dispatchedPath,
    ) {
        // Wait for the surrounding transaction, if any — without this a
        // worker could start before the row is committed and read the
        // previous path. afterCommit() is Queueable's own method (the same
        // trait already used above for onConnection()/onQueue()), not the
        // ->afterCommit() chaining method PendingDispatch offers, so there
        // was never an Illuminate\Foundation dependency to avoid here.
        $this->afterCommit();
    }

    public function handle(): void
    {
        $model = $this->modelClass::query()->find($this->modelKey);

        if ($model === null) {
            // Deleted since dispatch. Nothing to convert, nothing to report.
            return;
        }

        if (! method_exists($model, 'regeneratePhotoConversions')) {
            // A model class that doesn't use HasPhotos was dispatched here —
            // a programming error (wrong $modelClass), not a race any caller
            // can hit legitimately. Checked before the superseded-upload
            // comparison below: an unrelated model's column would almost
            // never match $dispatchedPath, so that check would return
            // quietly first and mask this one every time it actually matters.
            throw new PhotoDefinitionException(sprintf(
                '%s does not use the HasPhotos trait, so its [%s] conversions cannot be regenerated.',
                $this->modelClass,
                $this->field
            ));
        }

        if (($model->getAttributes()[$this->field] ?? null) !== $this->dispatchedPath) {
            // Superseded: a newer upload already replaced this field and
            // dispatched its own job for the current primary. This job's
            // primary is gone, so there is nothing left for it to do.
            //
            // getAttributes(), not getAttribute(): the latter applies casts,
            // and $dispatchedPath is the raw string the column was written
            // as. A cast on this field (e.g. AsStringable) would make
            // getAttribute() return a non-string object that can never equal
            // $dispatchedPath, permanently no-op-ing this job. HasPhotos
            // itself reads the same raw array internally for this reason —
            // see eloquentPhotoRawPath().
            return;
        }

        $model->regeneratePhotoConversions($this->field);
    }
}
