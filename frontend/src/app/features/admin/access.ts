import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { messageFrom } from '../../core/forms';
import { AccessRow } from '../../core/models';
import { TPipe } from '../../core/i18n/t.pipe';
import { Api } from '../../core/services/api';

/**
 * Find a person, switch their sign-in off or on, and fix their subscription by hand
 * (for example when a payment went through but the unlock did not).
 */
@Component({
  selector: 'app-access',
  imports: [TPipe, DatePipe],
  template: `
    <section class="card" id="access">
      <div class="card__head">
        <h2>{{ 'Access and subscriptions' | t }}</h2>
        <label class="search">
          <span class="visually-hidden">{{ 'Search by name or email' | t }}</span>
          <input type="search" [placeholder]="'Search by name or email' | t" #term (keyup.enter)="search(term.value)" (search)="search(term.value)" />
        </label>
      </div>
      <p class="muted">{{ 'Search for a creator or brand member. A brand’s subscription covers its whole team.' | t }}</p>

      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }

      @if (searched() && rows().length === 0) {
        <p class="muted">{{ 'No one matches.' | t }}</p>
      } @else if (rows().length > 0) {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Name' | t }}</th>
                <th scope="col">{{ 'Type' | t }}</th>
                <th scope="col">{{ 'Subscription' | t }}</th>
                <th scope="col">{{ 'Sign-in' | t }}</th>
                <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track row.id) {
                <tr>
                  <td>
                    <strong>{{ row.name }}</strong>
                    <span class="person__handle">{{ row.email }}{{ row.company ? ' · ' + row.company : '' }}</span>
                  </td>
                  <td>{{ (row.kind === 'creator' ? 'Creator' : 'Brand') | t }}</td>
                  <td>
                    <span class="badge" [class.badge--ok]="row.subscription.active" [class.badge--bad]="!row.subscription.active && row.subscription.status !== 'none'">
                      {{ (row.subscription.active ? 'Active' : row.subscription.status === 'none' ? 'Not subscribed' : 'Not active') | t }}
                    </span>
                    @if (row.subscription.until) {
                      <span class="person__handle">{{ 'until' | t }} {{ row.subscription.until | date: 'mediumDate' }}</span>
                    }
                  </td>
                  <td>
                    <span class="badge" [class.badge--ok]="!row.deactivated" [class.badge--warn]="row.deactivated">
                      {{ (row.deactivated ? 'Deactivated' : 'Allowed') | t }}
                    </span>
                  </td>
                  <td class="actions">
                    <select [attr.aria-label]="'Days of access' | t" #days>
                      <option value="30">30 {{ 'days' | t }}</option>
                      <option value="90">90 {{ 'days' | t }}</option>
                      <option value="365">365 {{ 'days' | t }}</option>
                    </select>
                    <button class="btn" type="button" [disabled]="busy()" (click)="grant(row, days.value)">{{ 'Give access' | t }}</button>
                    <button class="btn btn--quiet" type="button" [disabled]="busy() || !row.subscription.active" (click)="revoke(row)">{{ 'Remove access' | t }}</button>
                    @if (row.deactivated) {
                      <button class="btn" type="button" [disabled]="busy()" (click)="setActive(row, true)">{{ 'Reactivate' | t }}</button>
                    } @else if (confirming() === row.id) {
                      <span>{{ 'Sign {name} out and block sign-in?' | t: { name: row.name } }}</span>
                      <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="setActive(row, false)">{{ 'Deactivate' | t }}</button>
                      <button class="btn" type="button" (click)="confirming.set(null)">{{ 'Cancel' | t }}</button>
                    } @else {
                      <button class="btn btn--quiet" type="button" (click)="confirming.set(row.id)">{{ 'Deactivate' | t }}</button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </section>
  `,
})
export class Access {
  private readonly api = inject(Api);

  protected readonly rows = signal<AccessRow[]>([]);
  protected readonly searched = signal(false);
  protected readonly busy = signal(false);
  protected readonly confirming = signal<number | null>(null);
  protected readonly notice = signal('');
  protected readonly error = signal('');

  protected search(term: string): void {
    this.error.set('');
    this.notice.set('');
    if (term.trim().length < 2) {
      this.error.set('Type at least two letters.');
      return;
    }
    this.api.adminAccess(term.trim()).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.searched.set(true);
      },
      error: (err: unknown) => this.error.set(messageFrom(err, 'The search failed.')),
    });
  }

  protected grant(row: AccessRow, days: string): void {
    this.run(this.api.adminSetSubscription(row.id, 'grant', Number(days)), row, 'Access given.');
  }

  protected revoke(row: AccessRow): void {
    this.run(this.api.adminSetSubscription(row.id, 'revoke'), row, 'Access removed.');
  }

  protected setActive(row: AccessRow, active: boolean): void {
    this.confirming.set(null);
    this.run(this.api.adminSetActive(row.id, active), row, active ? 'Account reactivated.' : 'Account deactivated.');
  }

  private run(call: ReturnType<Api['adminSetActive']>, row: AccessRow, done: string): void {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    call.subscribe({
      next: (updated) => {
        // The same company can appear for several people, so refresh the rows from the server.
        this.rows.update((list) => list.map((r) => (r.id === updated.id ? updated : r)));
        this.notice.set(done);
        this.busy.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'That change could not be saved.'));
        this.busy.set(false);
      },
    });
  }
}
