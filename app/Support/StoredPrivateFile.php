<?php

namespace App\Support;

final readonly class StoredPrivateFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $sizeBytes,
        public string $mimeType,
        public ?string $extension,
    ) {}

    /** @return array{disk:string,path:string,size_bytes:int,mime_type:string,extension:?string} */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'size_bytes' => $this->sizeBytes,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
        ];
    }
}
