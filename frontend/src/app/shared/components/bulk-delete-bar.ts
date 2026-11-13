import { Component, input, output, signal } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';

/**
 * Shown when rows are ticked. Deleting is permanent, so the second step explains what goes
 * with the rows and only unlocks after typing DELETE (the word is the same in every language).
 */
@Component({
  selector: 'app-bulk-delete-bar',
  imports: [TPipe],
  template: `
    @if (count() > 0) {
      <div class="bulkbar" role="region" [attr.aria-label]="'Selected rows' | t">
        @if (!confirming()) {
          <strong>{{ '{count} selected' | t: { count: count() } }}</strong>
          <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="confirming.set(true)">{{ 'Delete selected' | t }}</button>
          <button class="btn btn--quiet" type="button" (click)="cleared.emit()">{{ 'Clear selection' | t }}</button>
        } @else {
          <div class="bulkbar__confirm" role="alertdialog" aria-labelledby="bulk-title" aria-describedby="bulk-detail">
            <p id="bulk-title"><strong>{{ 'Permanently delete {count} selected?' | t: { count: count() } }}</strong></p>
            <p id="bulk-detail">{{ consequence() }} {{ 'This cannot be undone.' | t }}</p>
            <div class="bulkbar__row">
              <label class="visually-hidden" for="bulk-confirm">{{ 'Type DELETE to confirm' | t }}</label>
              <input id="bulk-confirm" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false"
                     [placeholder]="'Type DELETE to confirm' | t" #typed (input)="word.set(typed.value)" />
              <button class="btn btn--danger" type="button" [disabled]="busy() || word() !== 'DELETE'" (click)="confirm()">
                {{ (busy() ? 'Deleting…' : 'Delete permanently') | t }}
              </button>
              <button class="btn" type="button" [disabled]="busy()" (click)="cancel()">{{ 'Cancel' | t }}</button>
            </div>
          </div>
        }
      </div>
    }
  `,
})
export class BulkDeleteBar {
  /** How many rows are ticked. */
  readonly count = input.required<number>();
  /** What else goes with the rows, already translated. */
  readonly consequence = input('');
  readonly busy = input(false);
  readonly confirmed = output<void>();
  readonly cleared = output<void>();

  protected readonly confirming = signal(false);
  protected readonly word = signal('');

  protected confirm(): void {
    this.confirmed.emit();
    this.cancel();
  }

  protected cancel(): void {
    this.confirming.set(false);
    this.word.set('');
  }
}
