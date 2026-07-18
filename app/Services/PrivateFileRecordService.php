<?php

namespace App\Services;

use App\Models\ApplicationInformationResponseAttachment;
use App\Models\CVFile;
use App\Models\TestAnswer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PrivateFileRecordService
{
    /**
     * @return array<string, array{model:class-string<Model>,disk:string,path:string,size:string,prefix:string,extension:?string}>
     */
    public function definitions(): array
    {
        return [
            'cv' => ['model' => CVFile::class, 'disk' => 'disk', 'path' => 'stored_path', 'size' => 'size_bytes', 'prefix' => 'cv-files', 'extension' => 'extension'],
            'test-answers' => ['model' => TestAnswer::class, 'disk' => 'file_disk', 'path' => 'file_path', 'size' => 'file_size', 'prefix' => 'test-answer-files', 'extension' => null],
            'information-attachments' => ['model' => ApplicationInformationResponseAttachment::class, 'disk' => 'disk', 'path' => 'stored_path', 'size' => 'size_bytes', 'prefix' => 'application-information-files', 'extension' => 'extension'],
        ];
    }

    /** @return array<string, array{model:class-string<Model>,disk:string,path:string,size:string,prefix:string,extension:?string}> */
    public function selected(string $domain): array
    {
        $definitions = $this->definitions();
        if ($domain === 'all') {
            return $definitions;
        }
        if (! isset($definitions[$domain])) {
            throw new InvalidArgumentException('Unsupported private file domain.');
        }

        return [$domain => $definitions[$domain]];
    }

    /** @param array{model:class-string<Model>,disk:string,path:string,size:string,prefix:string,extension:?string} $definition */
    public function query(array $definition, ?string $disk = null): Builder
    {
        return $definition['model']::query()
            ->whereNotNull($definition['path'])
            ->whereNotNull($definition['disk'])
            ->when($disk !== null, fn (Builder $query) => $query->where($definition['disk'], $disk));
    }

    /** @param array{model:class-string<Model>,disk:string,path:string,size:string,prefix:string,extension:?string} $definition */
    public function values(Model $record, array $definition): array
    {
        $path = (string) $record->getAttribute($definition['path']);

        return [
            'disk' => (string) $record->getAttribute($definition['disk']),
            'path' => $path,
            'size' => (int) $record->getAttribute($definition['size']),
            'extension' => $definition['extension'] === null
                ? (pathinfo($path, PATHINFO_EXTENSION) ?: null)
                : ($record->getAttribute($definition['extension']) ?: null),
        ];
    }
}
