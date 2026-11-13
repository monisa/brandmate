import { Component, inject, input } from '@angular/core';
import { I18n } from '../../core/i18n/i18n';
import { AbstractControl } from '@angular/forms';
import { TPipe } from '../../core/i18n/t.pipe';

/** Shows the first problem with a form control once the user has left it. */
@Component({
  selector: 'app-field-error',
  imports: [TPipe],
  template: `
    @if (message(); as text) {
      <p class="field-error" role="alert">{{ text | t }}</p>
    }
  `,
})
export class FieldError {
  private readonly i18n = inject(I18n);
  readonly control = input.required<AbstractControl>();

  protected message(): string | null {
    const control = this.control();
    const errors = control.errors;
    if (!control.touched || !errors) {
      return null;
    }
    if (errors['server']) {
      return String(errors['server']);
    }
    if (errors['required']) {
      return 'This field is required.';
    }
    if (errors['email']) {
      return 'Enter a valid email address.';
    }
    if (errors['minlength']) {
      return this.i18n.t('Use at least {n} characters.', { n: errors['minlength'].requiredLength });
    }
    if (errors['mismatch']) {
      return 'The two passwords do not match.';
    }
    return 'Check this value.';
  }
}
