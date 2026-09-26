/** An attachment as listed by AttachmentPanel (App\Http\Resources\AttachmentResource). */
export type Attachment = {
    /** The media UUID, used in download and delete URLs. */
    id: string;
    name: string;
    size: number;
    extension: string | null;
    previewable: boolean;
    uploader: { id: number; name: string } | null;
    created_at: string;
};

/** A collection's limits (AttachmentCollection::toFrontend). The server re-checks all of them. */
export type AttachmentRules = {
    name: string;
    max_files: number;
    max_size_kb: number;
    /** For the file input's accept attribute: extensions and MIME types. */
    accept: string;
    /** E.g. "PDF, JPG, JPEG, PNG". */
    type_list: string;
};

/** Props from App\Support\Attachments\AttachmentPanel::props(). */
export type AttachmentPanelData = {
    target: { type: string; id: number | string; collection: string };
    rules: AttachmentRules;
    items: Attachment[];
    can: { upload: boolean; delete: boolean };
};
