import assert from 'node:assert/strict';
import test from 'node:test';
import { retainedStudentSignature } from '../../resources/js/pages/i983/student-form/signature-source.ts';

test('a drawing stays available when the student leaves Section 2 and comes back', () => {
    assert.equal(retainedStudentSignature(true, false, false), 'drawing');
    assert.equal(retainedStudentSignature(true, true, false), 'drawing');
});

test('a saved draft is restored after refresh when the canvas file is gone', () => {
    assert.equal(retainedStudentSignature(false, true, false), 'stored');
});

test('clearing the pad does not restore the previous draft until a new drawing is saved', () => {
    assert.equal(retainedStudentSignature(false, true, true), 'empty');
    assert.equal(retainedStudentSignature(false, false, false), 'empty');
});
