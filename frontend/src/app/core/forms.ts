import { HttpErrorResponse } from '@angular/common/http';
import { AbstractControl, FormGroup, ValidationErrors } from '@angular/forms';

/** A sentence a person can act on, for any failed API call. */
export function messageFrom(err: unknown, fallback = 'That did not work. Try again.'): string {
  if (err instanceof HttpErrorResponse) {
    if (err.status === 0) {
      return 'Cannot reach the server. Check your connection and try again.';
    }
    if (err.status === 429) {
      return 'Too many attempts. Wait a minute and try again.';
    }
    const body = err.error as { message?: string; errors?: Record<string, string[]> } | null;
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    return first ?? body?.message ?? fallback;
  }
  return fallback;
}

/**
 * Puts Laravel's 422 validation messages onto the matching form controls.
 * Returns true when at least one message found its control.
 */
export function applyServerErrors(form: FormGroup, err: unknown): boolean {
  if (!(err instanceof HttpErrorResponse) || err.status !== 422) {
    return false;
  }
  const errors = ((err.error as { errors?: Record<string, string[]> } | null)?.errors) ?? {};
  let applied = false;
  for (const [field, messages] of Object.entries(errors)) {
    const control = form.get(field);
    if (control) {
      control.setErrors({ server: messages[0] });
      control.markAsTouched();
      applied = true;
    }
  }
  return applied;
}

/** Group validator: `password` and `password_confirmation` must be equal. */
export function passwordsMatch(group: AbstractControl): ValidationErrors | null {
  const password = group.get('password');
  const confirmation = group.get('password_confirmation');
  if (!password || !confirmation || !confirmation.value) {
    return null;
  }
  if (password.value !== confirmation.value) {
    confirmation.setErrors({ ...(confirmation.errors ?? {}), mismatch: true });
  } else if (confirmation.hasError('mismatch')) {
    const { mismatch: _removed, ...rest } = confirmation.errors ?? {};
    confirmation.setErrors(Object.keys(rest).length ? rest : null);
  }
  return null;
}
