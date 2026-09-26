import { describe, expect, it } from 'vite-plus/test';
import { attachmentProblem } from '@/lib/attachments';
import type { AttachmentRules } from '@/types';

const rules: AttachmentRules = {
    name: 'dokumen',
    max_files: 10,
    max_size_kb: 1,
    accept: '.pdf,.jpg,.jpeg,application/pdf,image/jpeg',
    type_list: 'PDF, JPG, JPEG',
};

const file = (name: string, bytes: number) =>
    new File([new Uint8Array(bytes)], name);

describe('attachmentProblem', () => {
    it('accepts an allowed extension within the size limit, in any case', () => {
        expect(attachmentProblem(file('Surat.PDF', 1024), rules)).toBeNull();
    });

    it('names the file and the allowed types for another extension', () => {
        expect(attachmentProblem(file('gambar.svg', 10), rules)).toBe(
            'gambar.svg: jenis berkas tidak diizinkan. Gunakan PDF, JPG, JPEG.',
        );
    });

    it('names the file and the limit when it is too large', () => {
        expect(attachmentProblem(file('scan.jpg', 1025), rules)).toBe(
            'scan.jpg: melebihi batas 1 KB.',
        );
    });
});
