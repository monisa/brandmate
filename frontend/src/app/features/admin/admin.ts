import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { messageFrom } from '../../core/forms';
import { AdminOverview, Company, User } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-admin',
  imports: [TPipe, DatePipe],
  template: `
    <header class="page-head">
      <h1>{{ 'Platform admin' | t }}</h1>
      <p class="muted">{{ 'Every company and user on the platform.' | t }}</p>
    </header>

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (overview(); as o) {
      <section class="facts" [attr.aria-label]="'Platform summary' | t">
        <div class="fact"><span class="fact__label">{{ 'Companies' | t }}</span><span class="fact__value">{{ o.companies }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Suspended' | t }}</span><span class="fact__value">{{ o.suspended_companies }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Users' | t }}</span><span class="fact__value">{{ o.users }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Email not confirmed' | t }}</span><span class="fact__value">{{ o.unverified_users }}</span></div>
      </section>
    }

    <section class="card">
      <div class="card__head">
        <h2>{{ 'Companies' | t }}</h2>
        <label class="search">
          <span class="visually-hidden">{{ 'Search companies by name' | t }}</span>
          <input type="search" [placeholder]="'Search by name' | t" #companySearch (keyup.enter)="loadCompanies(companySearch.value)" (search)="loadCompanies(companySearch.value)" />
        </label>
      </div>
      @if (companies().length === 0) {
        <p class="muted">{{ 'No companies match.' | t }}</p>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Company' | t }}</th>
                <th scope="col">{{ 'Members' | t }}</th>
                <th scope="col">{{ 'Created' | t }}</th>
                <th scope="col">{{ 'Status' | t }}</th>
                <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
              </tr>
            </thead>
            <tbody>
              @for (company of companies(); track company.id) {
                <tr>
                  <td>{{ company.name }}</td>
                  <td>{{ company.members_count ?? '–' }}</td>
                  <td>{{ company.created_at | date: 'mediumDate' }}</td>
                  <td>
                    <span class="badge" [class.badge--ok]="company.status === 'active'" [class.badge--warn]="company.status === 'suspended'">
                      {{ (company.status === 'active' ? 'Active' : 'Suspended') | t }}
                    </span>
                  </td>
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
      @if (users().length === 0) {
        <p class="muted">{{ 'No users match.' | t }}</p>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Name' | t }}</th>
                <th scope="col">{{ 'Email' | t }}</th>
                <th scope="col">{{ 'Company' | t }}</th>
                <th scope="col">{{ 'Role' | t }}</th>
              </tr>
            </thead>
            <tbody>
              @for (user of users(); track user.id) {
                <tr>
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

  protected readonly overview = signal<AdminOverview | null>(null);
  protected readonly companies = signal<Company[]>([]);
  protected readonly users = signal<User[]>([]);
  protected readonly confirming = signal<number | null>(null);
  protected readonly error = signal('');

  ngOnInit(): void {
    this.loadOverview();
    this.loadCompanies('');
    this.loadUsers('');
  }

  protected loadCompanies(search: string): void {
    this.api.adminCompanies(search.trim()).subscribe({
      next: (res) => this.companies.set(res.data),
      error: (err: unknown) => this.error.set(messageFrom(err, 'Companies could not be loaded.')),
    });
  }

  protected loadUsers(search: string): void {
    this.api.adminUsers(search.trim()).subscribe({
      next: (res) => this.users.set(res.data),
      error: (err: unknown) => this.error.set(messageFrom(err, 'Users could not be loaded.')),
    });
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

  private loadOverview(): void {
    this.api.adminOverview().subscribe({
      next: (overview) => this.overview.set(overview),
      error: () => this.overview.set(null),
    });
  }
}
