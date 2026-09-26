<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentType;
use App\Rules\AllowedAttachment;

/**
 * The rules of one attachment collection on a model: how many files, how
 * large, and which types. Models declare theirs in attachmentCollections().
 */
final readonly class AttachmentCollection
{
    /**
     * @var list<AttachmentType>
     */
    public array $types;

    /**
     * @param  list<AttachmentType>|null  $types  every AttachmentType when null
     */
    public function __construct(
        public string $name,
        public int $maxFiles,
        public int $maxSizeKb,
        ?array $types = null,
    ) {
        $this->types = $types ?? AttachmentType::cases();
    }

    public function accepts(AttachmentType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * Validation rules for one uploaded file.
     *
     * @return list<mixed>
     */
    public function fileRules(): array
    {
        return ['bail', 'required', 'file', 'max:'.$this->maxSizeKb, new AllowedAttachment($this)];
    }

    /**
     * Extensions users know the accepted types by, e.g. "PDF, JPG, JPEG".
     */
    public function typeList(): string
    {
        return mb_strtoupper(implode(', ', $this->extensions()));
    }

    /**
     * What the upload panel needs to guide the user; the server re-checks all of it.
     *
     * @return array{name: string, max_files: int, max_size_kb: int, accept: string, type_list: string}
     */
    public function toFrontend(): array
    {
        return [
            'name' => $this->name,
            'max_files' => $this->maxFiles,
            'max_size_kb' => $this->maxSizeKb,
            'accept' => implode(',', [
                ...array_map(fn (string $extension): string => '.'.$extension, $this->extensions()),
                ...array_map(fn (AttachmentType $type): string => $type->value, $this->types),
            ]),
            'type_list' => $this->typeList(),
        ];
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        return array_merge(...array_map(fn (AttachmentType $type): array => $type->knownExtensions(), $this->types));
    }
}
