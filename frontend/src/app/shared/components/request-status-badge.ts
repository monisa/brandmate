import { Component, input } from '@angular/core';
import { RequestStatus } from '../../core/models';
import { TPipe } from '../../core/i18n/t.pipe';

/** Where a request stands. The word always accompanies the colour. */
@Component({
  selector: 'app-request-status-badge',
  imports: [TPipe],
  template: `
    <span class="badge" [class.badge--ok]="status() === 'accepted'" [class.badge--warn]="status() === 'pending'"
          [class.badge--bad]="status() === 'declined'">{{ label() | t }}</span>
  `,
})
export class RequestStatusBadge {
  readonly status = input.required<RequestStatus>();

  protected label(): string {
    switch (this.status()) {
      case 'accepted':
        return 'Accepted';
      case 'declined':
        return 'Declined';
      case 'withdrawn':
        return 'Withdrawn';
      default:
        return 'Waiting for an answer';
    }
  }
}
