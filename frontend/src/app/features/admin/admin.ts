import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { messageFrom } from '../../core/forms';
import { AuthService } from '../../core/auth/auth.service';
import { AdminOverview, Company, User } from '../../core/models';
import { bulkSummary } from '../../core/bulk-summary';
import { RowSelection } from '../../core/row-selection';
import { I18n } from '../../core/i18n/i18n';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { ApprovalBadge } from '../../shared/components/approval-badge';
import { BulkDeleteBar } from '../../shared/components/bulk-delete-bar';
import { Approvals } from './approvals';

@Component({
  selector: 'app-admin',
  imports: [TPipe, DatePipe, Approvals, ApprovalBadge, BulkDeleteBar],
  template: `
    <header class="page-head">
      <h1>{{ 'Platform admin' | t }}</h1>
      <p class="muted">{{ 'Every company and user on the platform.' | t }}</p>
    </header>

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }
    @if (notice()) {
      <p class="alert alert--success" role="status">{{ notice() }}</p>
    }

    @if (overview(); as o) {
      <section class="facts" [attr.aria-label]="'Platform summary' | t">
        <div class="fact"><span class="fact__label">{{ 'Waiting for approval' | t }}</span><span class="fact__value">{{ o.pending_companies + o.pending_creators }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Companies' | t }}</span><span class="fact__value">{{ o.companies }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Suspended' | t }}</span><span class="fact__value">{{ o.suspended_companies }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Users' | t }}</span><span class="fact__value">{{ o.users }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Email not confirmed' | t }}</span><span class="fact__value">{{ o.unverified_users }}</span></div>
      </section>
    }

    <app-approvals [pendingCompanies]="overview()?.pending_companies ?? 0" [pendingCreators]="overview()?.pending_creators ?? 0" (changed)="reloadAll()" />

    <section class="card">
      <div class="card__head">
        <h2>{{ 'Companies' | t }}</h2>
        <label class="search">
          <span class="visually-hidden">{{ 'Search companies by name' | t }}</span>
          <input type="search" [placeholder]="'Search by name' | t" #companySearch (keyup.enter)="loadCompanies(companySearch.value)" (search)="loadCompanies(companySearch.value)" />
        </label>
      </div>
      <app-bulk-delete-bar [count]="companySel.count()" [busy]="busy()" [consequence]="companyConsequence()"
                           (confirmed)="bulkDelete('companies', companySel)" (cleared)="companySel.clear()" />
      @if (companies().length === 0) {
        <p class="muted">{{ 'No companies match.' | t }}</p>
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
                <th scope="col">{{ 'Members' | t }}</th>
                <th scope="col">{{ 'Created' | t }}</th>
                <th scope="col">{{ 'Status' | t }}</th>
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
                  <td>{{ company.name }}</td>
                  <td>{{ company.members_count ?? '–' }}</td>
                  <td>{{ company.created_at | date: 'mediumDate' }}</td>
                  <td>
                    <span class="badge" [class.badge--ok]="company.status === 'active'" [class.badge--warn]="company.status === 'suspended'">
                      {{ (company.status === 'active' ? 'Active' : 'Suspended') | t }}
                    </span>
                  </td>
                  <td><app-approval-badge [status]="company.approval_status" /></td>
                  <td class="actions">
                    @if (company.status === 'active') {
                      @if (confirming() === company.id) {
                        <span>{{ 'Lock everyone at {name} out?' | t: { name: company.name } }}</span>
                        <button class="btn btn--danger" type="button" (click)="setStatus(company, 'suspended')">{{ 'Suspend' | t }}</button>
                        <button class="btn" type="button" (click)="confirming.set(null)">{{ 'Cancel' | t }}</button>
                      } @else {
                        <button class="btn btn--quiet" type="button" (click)="confirming.set(company.id)">{{ 'Suspend' | t }}</button>
                      }
                    } @else {
                      <button class="btn" type="button" (click)="setStatus(company, 'active')">{{ 'Reactivate' | t }}</button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </section>

    <section class="card">
      <div class="card__head">
        <h2>{{ 'Users' | t }}</h2>
        <label class="search">
          <span class="visually-hidden">{{ 'Search users by name or email' | t }}</span>
          <input type="search" [placeholder]="'Search by name or email' | t" #userSearch (keyup.enter)="loadUsers(userSearch.value)" (search)="loadUsers(userSearch.value)" />
        </label>
      </div>
      <app-bulk-delete-bar [count]="userSel.count()" [busy]="busy()" [consequence]="userConsequence()"
                           (confirmed)="bulkDelete('users', userSel)" (cleared)="userSel.clear()" />
      @if (users().length === 0) {
        <p class="muted">{{ 'No users match.' | t }}</p>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col" class="check">
                  <input type="checkbox" [checked]="userSel.allSelected(selectableUserIds())" (change)="userSel.toggleAll(selectableUserIds())"
                         [attr.aria-label]="'Select all users' | t" />
                </th>
                <th scope="col">{{ 'Name' | t }}</th>
                <th scope="col">{{ 'Email' | t }}</th>
                <th scope="col">{{ 'Company' | t }}</th>
                <th scope="col">{{ 'Role' | t }}</th>
              </tr>
            </thead>
            <tbody>
              @for (user of users(); track user.id) {
                <tr [class.is-selected]="userSel.has(user.id)">
                  <td class="check">
                    <input type="checkbox" [checked]="userSel.has(user.id)" (change)="userSel.toggle(user.id)" [disabled]="!isDeletable(user)"
                           [attr.aria-label]="'Select {name}' | t: { name: user.name }"
                           [attr.title]="isDeletable(user) ? null : (('Super admins and your own account cannot be deleted here' | t))" />
                  </td>
                  <td>{{ user.name }}</td>
                  <td>
                    {{ user.email }}
                    @if (!user.email_verified) {
                      <span class="badge badge--warn">{{ 'Not confirmed' | t }}</span>
                    }
                  </td>
                  <td>{{ user.company?.name ?? '–' }}</td>
                  <td>{{ (user.is_super_admin ? 'Super Admin' : (user.role?.name ?? '–')) | t }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </section>
  `,
})
export class Admin implements OnInit {
  private readonly api = inject(Api);
  private readonly i18n = inject(I18n);
  private readonly me = inject(AuthService).user;
  private readonly approvals = viewChild(Approvals);

  protected readonly overview = signal<AdminOverview | null>(null);
  protected readonly companies = signal<Company[]>([]);
  protected readonly users = signal<User[]>([]);
  protected readonly confirming = signal<number | null>(null);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly busy = signal(false);

  protected readonly companySel = new RowSelection();
  protected readonly userSel = new RowSelection();
  protected readonly companyIds = computed(() => this.companies().map((c) => c.id));
  protected readonly selectableUserIds = computed(() => this.users().filter((u) => this.isDeletable(u)).map((u) => u.id));

  private companyQuery = '';
  private userQuery = '';

  ngOnInit(): void {
    this.loadOverview();
    this.loadCompanies('');
    this.loadUsers('');
  }

  /** Super admins and your own account are never deleted; the API refuses them too. */
  protected isDeletable(user: User): boolean {
    return !user.is_super_admin && user.id !== this.me()?.id;
  }

  protected companyConsequence(): string {
    return this.i18n.t('This also deletes each company’s members, shortlists, invitations and requests.');
  }

  protected userConsequence(): string {
    return this.i18n.t('This also deletes creator profiles that belong to them, and any company left with no members.');
  }

  protected loadCompanies(search: string): void {
    this.companyQuery = search.trim();
    this.api.adminCompanies(this.companyQuery).subscribe({
      next: (res) => {
        this.companies.set(res.data);
        this.companySel.retain(res.data.map((c) => c.id));
      },
      error: (err: unknown) => this.error.set(messageFrom(err, 'Companies could not be loaded.')),
    });
  }

  protected loadUsers(search: string): void {
    this.userQuery = search.trim();
    this.api.adminUsers(this.userQuery).subscribe({
      next: (res) => {
        this.users.set(res.data);
        this.userSel.retain(res.data.filter((u) => this.isDeletable(u)).map((u) => u.id));
      },
      error: (err: unknown) => this.error.set(messageFrom(err, 'Users could not be loaded.')),
    });
  }

  /** Deletes the ticked rows. The bar has already asked for confirmation. */
  protected bulkDelete(type: 'users' | 'companies', selection: RowSelection): void {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    this.api.adminBulkDelete(type, selection.ids()).subscribe({
      next: (result) => {
        this.notice.set(bulkSummary(this.i18n, result));
        selection.clear();
        this.busy.set(false);
        this.reloadAll();
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The rows could not be deleted.'));
        this.busy.set(false);
      },
    });
  }

  /** Refreshes everything after something was deleted in the approvals queue or a table. */
  protected reloadAll(): void {
    this.loadOverview();
    this.loadCompanies(this.companyQuery);
    this.loadUsers(this.userQuery);
    this.approvals()?.reload();
  }

  protected setStatus(company: Company, status: Company['status']): void {
    this.error.set('');
    this.api.adminSetCompanyStatus(company.id, status).subscribe({
      next: (updated) => {
        this.companies.update((list) => list.map((c) => (c.id === updated.id ? updated : c)));
        this.confirming.set(null);
        this.loadOverview();
      },
      error: (err: unknown) => {
        this.confirming.set(null);
        this.error.set(messageFrom(err, 'The company status could not be changed.'));
      },
    });
  }

  protected loadOverview(): void {
    this.api.adminOverview().subscribe({
      next: (overview) => this.overview.set(overview),
      error: () => this.overview.set(null),
    });
  }
}
