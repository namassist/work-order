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
     * @param  int  $minFiles  files that must stay once the parent needs them (e.g. an invoice's file); removal below it is refused
     * @param  bool  $holdsPendingUploads  uploads waiting for a record to claim them (a comment's files): $maxFiles counts each uploader's own, and adding one is neither logged nor counted as activity on the parent
     * @param  bool  $loggedByRecord  files the parent's own action logs together with its other fields (a daily report's files, logged on its work order), so adding or removing one logs nothing by itself
     */
    public function __construct(
        public string $name,
        public int $maxFiles,
        public int $maxSizeKb,
        ?array $types = null,
        public int $minFiles = 0,
        public bool $holdsPendingUploads = false,
        public bool $loggedByRecord = false,
    ) {
        $this->types = $types ?? AttachmentType::cases();
    }

    /**
     * The same collection with room for $count more files, for an edit that
     * adds files before deleting the ones it replaces (deletions last,
     * since they cannot be rolled back).
     */
    public function withRoomFor(int $count): self
    {
        return new self($this->name, $this->maxFiles + max(0, $count), $this->maxSizeKb, $this->types, $this->minFiles, $this->holdsPendingUploads, $this->loggedByRecord);
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
