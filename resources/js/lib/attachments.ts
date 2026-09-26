import { formatFileSize } from '@/lib/format';
import type { AttachmentRules } from '@/types';

/**
 * Why a chosen file cannot be uploaded, checked before sending so the user
 * learns at once. Only a hint: the server checks the file's content, and its
 * answer is final.
 */
export function attachmentProblem(
    file: File,
    rules: AttachmentRules,
): string | null {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    const accepted = rules.accept
        .split(',')
        .filter((entry) => entry.startsWith('.'))
        .map((entry) => entry.slice(1));

    if (!file.name.includes('.') || !accepted.includes(extension)) {
        return `${file.name}: jenis berkas tidak diizinkan. Gunakan ${rules.type_list}.`;
    }

    if (file.size > rules.max_size_kb * 1024) {
        return `${file.name}: melebihi batas ${formatFileSize(rules.max_size_kb * 1024)}.`;
    }

    return null;
}
