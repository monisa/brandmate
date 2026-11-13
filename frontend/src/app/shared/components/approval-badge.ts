import { Component, input } from '@angular/core';
import { ApprovalStatus } from '../../core/models';
import { TPipe } from '../../core/i18n/t.pipe';

/** "Waiting for approval", "Approved" or "Rejected", colour and word together so colour is never the only cue. */
@Component({
  selector: 'app-approval-badge',
  imports: [TPipe],
  template: `
    <span class="badge" [class.badge--ok]="status() === 'approved'" [class.badge--warn]="status() === 'pending'" [class.badge--bad]="status() === 'rejected'">
      {{ label() | t }}
    </span>
  `,
})
export class ApprovalBadge {
  readonly status = input.required<ApprovalStatus>();

  protected label(): string {
    switch (this.status()) {
      case 'approved':
        return 'Approved';
      case 'rejected':
        return 'Rejected';
      default:
        return 'Waiting for approval';
    }
  }
}
