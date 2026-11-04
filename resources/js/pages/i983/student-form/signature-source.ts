export type RetainedSignature = 'drawing' | 'stored' | 'empty';

/**
 * The canvas is unmounted when the student leaves Section 2, so the drawing has to live
 * in form state or in the private draft restored for this link.
 */
export function retainedStudentSignature(filePresent: boolean, stored: boolean, cleared: boolean): RetainedSignature {
    if (filePresent) {
        return 'drawing';
    }

    if (stored && !cleared) {
        return 'stored';
    }

    return 'empty';
}
