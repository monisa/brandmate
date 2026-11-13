/**
 * Makes pasting into any field forgiving, app-wide:
 *  - e-mail, search, text and URL fields: leading and trailing spaces and line breaks are dropped
 *  - number fields: "12,500", "1 200", "₹ 1,200" and "4.5 %" are cleaned to a plain number
 *  - password fields are never touched: spaces can be part of a password
 *
 * Everything else about copy and paste is left to the browser, so Ctrl+C, Ctrl+V,
 * right-click paste and text selection all keep working.
 */

export type FieldKind = 'text' | 'number';

/** The text that should end up in the field, or null to leave the paste alone. */
export function cleanPasted(kind: FieldKind, text: string): string | null {
  if (kind === 'text') {
    const trimmed = text.replace(/\r?\n+/g, ' ').trim();
    return trimmed === text ? null : trimmed;
  }

  // Keep digits, one decimal point and a leading minus; drop separators, spaces and symbols.
  const stripped = text.trim().replace(/[\s,'’_]/g, '').replace(/^[^\d.-]+|[^\d.]+$/g, '');
  return /^-?\d*\.?\d+$/.test(stripped) && stripped !== text ? stripped : null;
}

const TEXT_TYPES = new Set(['text', 'email', 'search', 'url', 'tel']);

function kindOf(target: EventTarget | null): { input: HTMLInputElement; kind: FieldKind } | null {
  if (!(target instanceof HTMLInputElement) || target.readOnly || target.disabled) {
    return null;
  }
  if (target.type === 'number') {
    return { input: target, kind: 'number' };
  }
  return TEXT_TYPES.has(target.type) ? { input: target, kind: 'text' } : null;
}

/**
 * Puts text into the field exactly as if it was typed: it replaces the selection, keeps
 * Ctrl+Z working and lets Angular forms see the change. E-mail and number inputs have no
 * selection API (setRangeText throws there), so this goes through the editing command.
 */
function insert(doc: Document, input: HTMLInputElement, text: string): void {
  input.focus();
  if (doc.execCommand('insertText', false, text)) {
    return;
  }
  // Fallback for browsers without the command: the cleaned text replaces the whole value.
  input.value = text;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

export function installPasteCleaning(doc: Document = document): void {
  doc.addEventListener('paste', (event) => {
    const field = kindOf(event.target);
    const text = event.clipboardData?.getData('text/plain');
    if (!field || text === undefined || text === '') {
      return;
    }
    const cleaned = cleanPasted(field.kind, text);
    if (cleaned === null) {
      return;
    }
    event.preventDefault();
    insert(doc, field.input, cleaned);
  });

  // An e-mail filled in by autofill or a password manager can carry a trailing space too.
  doc.addEventListener('focusout', (event) => {
    const field = kindOf(event.target);
    if (field?.input.type === 'email' && field.input.value !== field.input.value.trim()) {
      field.input.value = field.input.value.trim();
      field.input.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
}
