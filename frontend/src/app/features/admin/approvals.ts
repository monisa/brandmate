import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { bulkSummary } from '../../core/bulk-summary';
import { messageFrom } from '../../core/forms';
import { RowSelection } from '../../core/row-selection';
import { I18n } from '../../core/i18n/i18n';
import { AdminCreator, ApprovalStatus, Company } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { ApprovalBadge } from '../../shared/components/approval-badge';
import { BulkDeleteBar } from '../../shared/components/bulk-delete-bar';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { PLATFORM_LABELS, countryName } from '../influencers/labels';

type Tab = 'companies' | 'creators';
type Filter = ApprovalStatus | '';

/**
 * The platform team's review queue. Brands and creators sign up as "waiting"; only
 * approved creators appear in search, and only approved brands can search at all.
 */
@Component({
  selector: 'app-approvals',
  imports: [TPipe, DatePipe, MetricPipe, ApprovalBadge, BulkDeleteBar],
  template: `
    <section class="card" id="approvals">
      <div class="card__head">
        <h2>{{ 'Approvals' | t }}</h2>
        <div class="sort">
          <label for="approval-filter">{{ 'Show' | t }}</label>
          <select id="approval-filter" [value]="filter()" (change)="setFilter($any($event.target).value)">
            <option value="pending" [selected]="filter() === 'pending'">{{ 'Waiting for approval' | t }}</option>
            <option value="approved" [selected]="filter() === 'approved'">{{ 'Approved' | t }}</option>
            <option value="rejected" [selected]="filter() === 'rejected'">{{ 'Rejected' | t }}</option>
            <option value="" [selected]="filter() === ''">{{ 'All' | t }}</option>
          </select>
        </div>
      </div>

      <div class="tabs" role="tablist" [attr.aria-label]="'What to review' | t">
        <button type="button" role="tab" class="tabs__tab" [class.is-active]="tab() === 'companies'"
                [attr.aria-selected]="tab() === 'companies'" (click)="setTab('companies')">
          {{ 'Brands' | t }}
          @if (pendingCompanies() > 0) {
            <span class="count-pill">{{ pendingCompanies() }}</span>
          }
        </button>
        <button type="button" role="tab" class="tabs__tab" [class.is-active]="tab() === 'creators'"
                [attr.aria-selected]="tab() === 'creators'" (click)="setTab('creators')">
          {{ 'Creators' | t }}
          @if (pendingCreators() > 0) {
            <span class="count-pill">{{ pendingCreators() }}</span>
          }
        </button>
      </div>

      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }

      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() }}</p>
      }
      @if (tab() === 'companies') {
        <app-bulk-delete-bar [count]="companySel.count()" [busy]="busy()" [consequence]="companyConsequence()"
                             (confirmed)="bulkDelete('companies')" (cleared)="companySel.clear()" />
      } @else {
        <app-bulk-delete-bar [count]="creatorSel.count()" [busy]="busy()" [consequence]="creatorConsequence()"
                             (confirmed)="bulkDelete('creators')" (cleared)="creatorSel.clear()" />
      }

      @if (loading()) {
        <p class="muted" role="status">{{ 'Loading…' | t }}</p>
      } @else if (tab() === 'companies') {
        @if (companies().length === 0) {
          <p class="muted">{{ (filter() === 'pending' ? 'No brands are waiting for approval.' : 'No brands match.') | t }}</p>
        } @else {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col" class="check">
                    <input type="checkbox" [checked]="companySel.allSelected(companyIds())" (change)="companySel.toggleAll(companyIds())"
                           [attr.aria-label]="'Select all companies' | t" />
                  </th>
                  <th scope="col">{{ 'Company' | t }}</th>
                  <th scope="col">{{ 'Signed up by' | t }}</th>
                  <th scope="col">{{ 'Created' | t }}</th>
                  <th scope="col">{{ 'Approval' | t }}</th>
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                </tr>
              </thead>
              <tbody>
                @for (company of companies(); track company.id) {
                  <tr [class.is-selected]="companySel.has(company.id)">
                    <td class="check">
                      <input type="checkbox" [checked]="companySel.has(company.id)" (change)="companySel.toggle(company.id)"
                             [attr.aria-label]="'Select {name}' | t: { name: company.name }" />
                    </td>
                    <td><strong>{{ company.name }}</strong></td>
                    <td>
                      @if (company.owner) {
                        {{ company.owner.name }}<span class="person__handle">{{ company.owner.email }}</span>
                      } @else {
                        –
                      }
                    </td>
                    <td>{{ company.created_at | date: 'mediumDate' }}</td>
                    <td>
                      <app-approval-badge [status]="company.approval_status" />
                      @if (company.review_note) {
                        <span class="person__handle">{{ company.review_note }}</span>
                      }
                    </td>
                    <td class="actions">
                      @if (rejecting() === 'c' + company.id) {
                        <span class="reject">
                          <label class="visually-hidden" [for]="'note-c' + company.id">{{ 'Reason shown to them (optional)' | t }}</label>
                          <input [id]="'note-c' + company.id" type="text" maxlength="500" [placeholder]="'Reason shown to them (optional)' | t" #note />
                          <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="decideCompany(company, 'rejected', note.value)">{{ 'Confirm' | t }}</button>
                          <button class="btn" type="button" (click)="rejecting.set(null)">{{ 'Cancel' | t }}</button>
                        </span>
                      } @else {
                        @if (company.approval_status !== 'approved') {
                          <button class="btn btn--primary" type="button" [disabled]="busy()" (click)="decideCompany(company, 'approved')">{{ 'Approve' | t }}</button>
                        }
                        @if (company.approval_status !== 'rejected') {
                          <button class="btn btn--quiet" type="button" [disabled]="busy()" (click)="rejecting.set('c' + company.id)">
                            {{ (company.approval_status === 'approved' ? 'Revoke' : 'Reject') | t }}
                          </button>
                        }
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      } @else {
        @if (creators().length === 0) {
          <p class="muted">{{ (filter() === 'pending' ? 'No creators are waiting for approval.' : 'No creators match.') | t }}</p>
        } @else {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col" class="check">
                    <input type="checkbox" [checked]="creatorSel.allSelected(creatorIds())" (change)="creatorSel.toggleAll(creatorIds())"
                           [attr.aria-label]="'Select all creators' | t" />
                  </th>
                  <th scope="col">{{ 'Creator' | t }}</th>
                  <th scope="col">{{ 'Category' | t }}</th>
                  <th scope="col">{{ 'Social accounts' | t }}</th>
                  <th scope="col">{{ 'Created' | t }}</th>
                  <th scope="col">{{ 'Approval' | t }}</th>
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                </tr>
              </thead>
              <tbody>
                @for (creator of creators(); track creator.id) {
                  <tr [class.is-selected]="creatorSel.has(creator.id)">
                    <td class="check">
                      <input type="checkbox" [checked]="creatorSel.has(creator.id)" (change)="creatorSel.toggle(creator.id)"
                             [attr.aria-label]="'Select {name}' | t: { name: creator.name }" />
                    </td>
                    <td>
                      <strong>{{ creator.name }}</strong>
                      <span class="person__handle">{{ creator.email }}</span>
                    </td>
                    <td>
                      {{ creator.category ? (creator.category | t) : '–' }}
                      @if (creator.country) {
                        <span class="person__handle">{{ countryName(creator.country) }}</span>
                      }
                    </td>
                    <td>
                      @for (account of creator.accounts; track account.platform + account.username) {
                        <span class="person__handle">{{ platformLabels[account.platform] }} &#64;{{ account.username }} · {{ account.followers | metric }}</span>
                      } @empty {
                        <span class="muted">{{ 'None yet' | t }}</span>
                      }
                    </td>
                    <td>{{ creator.created_at | date: 'mediumDate' }}</td>
                    <td>
                      <app-approval-badge [status]="creator.approval_status" />
                      @if (creator.approval_status === 'approved' && !creator.listed) {
                        <span class="person__handle">{{ 'Not listed by the creator' | t }}</span>
                      }
                      @if (creator.review_note) {
                        <span class="person__handle">{{ creator.review_note }}</span>
                      }
                    </td>
                    <td class="actions">
                      @if (rejecting() === 'p' + creator.id) {
                        <span class="reject">
                          <label class="visually-hidden" [for]="'note-p' + creator.id">{{ 'Reason shown to them (optional)' | t }}</label>
                          <input [id]="'note-p' + creator.id" type="text" maxlength="500" [placeholder]="'Reason shown to them (optional)' | t" #note />
                          <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="decideCreator(creator, 'rejected', note.value)">{{ 'Confirm' | t }}</button>
                          <button class="btn" type="button" (click)="rejecting.set(null)">{{ 'Cancel' | t }}</button>
                        </span>
                      } @else {
                        @if (creator.approval_status !== 'approved') {
                          <button class="btn btn--primary" type="button" [disabled]="busy()" (click)="decideCreator(creator, 'approved')">{{ 'Approve' | t }}</button>
                        }
                        @if (creator.approval_status !== 'rejected') {
                          <button class="btn btn--quiet" type="button" [disabled]="busy()" (click)="rejecting.set('p' + creator.id)">
                            {{ (creator.approval_status === 'approved' ? 'Revoke' : 'Reject') | t }}
                          </button>
                        }
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      }
    </section>
  `,
})
export class Approvals implements OnInit {
  private readonly api = inject(Api);
  private readonly i18n = inject(I18n);

  /** Waiting counts from the overview, shown on the tabs. */
  readonly pendingCompanies = input(0);
  readonly pendingCreators = input(0);
  /** Fired after every decision so the overview numbers can refresh. */
  readonly changed = output<void>();

  protected readonly platformLabels = PLATFORM_LABELS;
  protected readonly countryName = countryName;

  protected readonly tab = signal<Tab>('companies');
  protected readonly filter = signal<Filter>('pending');
  protected readonly companies = signal<Company[]>([]);
  protected readonly creators = signal<AdminCreator[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');

  protected readonly companySel = new RowSelection();
  protected readonly creatorSel = new RowSelection();
  protected readonly companyIds = computed(() => this.companies().map((c) => c.id));
  protected readonly creatorIds = computed(() => this.creators().map((c) => c.id));
  /** Which row is asking for a rejection reason: "c<id>" for a company, "p<id>" for a creator. */
  protected readonly rejecting = signal<string | null>(null);

  ngOnInit(): void {
    this.load();
  }

  /** Reloads the visible list, for example after rows were deleted elsewhere on the page. */
  reload(): void {
    this.load(false); // no spinner: the list just refreshes in place
  }

  protected companyConsequence(): string {
    return this.i18n.t('This also deletes each company’s members, shortlists, invitations and requests.');
  }

  protected creatorConsequence(): string {
    return this.i18n.t('This also deletes their login, social accounts, packages and requests.');
  }

  /** Deletes the ticked rows on the current tab. The bar has already asked for confirmation. */
  protected bulkDelete(type: 'companies' | 'creators'): void {
    const selection = type === 'companies' ? this.companySel : this.creatorSel;
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    this.api.adminBulkDelete(type, selection.ids()).subscribe({
      next: (result) => {
        this.notice.set(bulkSummary(this.i18n, result));
        selection.clear();
        this.busy.set(false);
        this.load();
        this.changed.emit();
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The rows could not be deleted.'));
        this.busy.set(false);
      },
    });
  }

  protected setTab(tab: Tab): void {
    this.tab.set(tab);
    this.notice.set('');
    this.rejecting.set(null);
    this.load();
  }

  protected setFilter(value: Filter): void {
    this.filter.set(value);
    this.rejecting.set(null);
    this.load();
  }

  protected decideCompany(company: Company, decision: 'approved' | 'rejected', note = ''): void {
    this.decide(this.api.adminReviewCompany(company.id, decision, note.trim()), (updated) =>
      this.companies.update((list) => this.applyUpdate(list, updated)),
    );
  }

  protected decideCreator(creator: AdminCreator, decision: 'approved' | 'rejected', note = ''): void {
    this.decide(this.api.adminReviewCreator(creator.id, decision, note.trim()), (updated) =>
      this.creators.update((list) => this.applyUpdate(list, updated)),
    );
  }

  private decide<T>(request: Observable<T>, apply: (updated: T) => void): void {
    this.busy.set(true);
    this.error.set('');
    request.subscribe({
      next: (updated) => {
        apply(updated);
        this.rejecting.set(null);
        this.busy.set(false);
        this.changed.emit();
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The decision could not be saved.'));
        this.busy.set(false);
      },
    });
  }

  /** Replaces the row, or drops it when it no longer matches the status being shown. */
  private applyUpdate<T extends { id: number; approval_status: ApprovalStatus }>(list: T[], updated: T): T[] {
    const filter = this.filter();
    if (filter && updated.approval_status !== filter) {
      return list.filter((item) => item.id !== updated.id);
    }
    return list.map((item) => (item.id === updated.id ? updated : item));
  }

  private load(spinner = true): void {
    if (spinner) {
      this.loading.set(true);
    }
    this.error.set('');
    const fail = (err: unknown) => {
      this.error.set(messageFrom(err, 'The list could not be loaded.'));
      this.loading.set(false);
    };

    if (this.tab() === 'companies') {
      this.api.adminCompanies('', this.filter()).subscribe({
        next: (res) => {
          this.companies.set(res.data);
          this.companySel.retain(res.data.map((c) => c.id));
          this.loading.set(false);
        },
        error: fail,
      });
    } else {
      this.api.adminCreators('', this.filter()).subscribe({
        next: (res) => {
          this.creators.set(res.data);
          this.creatorSel.retain(res.data.map((c) => c.id));
          this.loading.set(false);
        },
        error: fail,
      });
    }
  }
}
