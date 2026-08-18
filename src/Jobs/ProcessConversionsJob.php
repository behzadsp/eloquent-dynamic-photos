<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Jobs;

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

        if ($model->getAttribute($this->field) !== $this->dispatchedPath) {
            // Superseded: a newer upload already replaced this field and
            // dispatched its own job for the current primary. This job's
            // primary is gone, so there is nothing left for it to do.
            return;
        }

        // @phpstan-ignore method.notFound (the model uses HasPhotos, which the class-string cannot express)
        $model->regeneratePhotoConversions($this->field);
    }
}
