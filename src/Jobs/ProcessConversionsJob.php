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
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int|string $modelKey,
        public readonly string $field,
    ) {
        // Wait for the surrounding transaction, if any — without this a
        // worker could start before the row is committed and read the
        // previous path. Assigned as a plain property, not the ->afterCommit()
        // chaining method PendingDispatch offers, because PendingDispatch
        // lives in Illuminate\Foundation, which src/ must not depend on.
        // It can't be redeclared as a typed class property either: Queueable
        // already declares $afterCommit untyped, and PHP treats a
        // differently-typed (or differently-defaulted) redeclaration of a
        // trait property as a fatal composition conflict.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $model = $this->modelClass::query()->find($this->modelKey);

        if ($model === null) {
            // Deleted since dispatch. Nothing to convert, nothing to report.
            return;
        }

        // @phpstan-ignore method.notFound (the model uses HasPhotos, which the class-string cannot express)
        $model->regeneratePhotoConversions($this->field);
    }
}
