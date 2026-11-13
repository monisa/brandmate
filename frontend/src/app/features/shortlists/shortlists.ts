import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { Shortlist } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-shortlists',
  imports: [TPipe, RouterLink, DatePipe],
  template: `
    <header class="page-head">
      <h1>{{ 'Shortlists' | t }}</h1>
      <p class="muted">{{ 'Influencers your team has saved. Everyone in your company sees the same shortlists.' | t }}</p>
    </header>

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (canManage) {
      <section class="card">
        <h2>{{ 'Create a shortlist' | t }}</h2>
        <div class="picker">
          <label class="visually-hidden" for="new-shortlist">{{ 'Shortlist name' | t }}</label>
          <input id="new-shortlist" type="text" maxlength="120" [placeholder]="'For example, Diwali campaign' | t"
                 [value]="newName()" (input)="newName.set($any($event.target).value)" (keyup.enter)="create()" />
          <button class="btn btn--primary" type="button" (click)="create()" [disabled]="busy()">{{ 'Create shortlist' | t }}</button>
        </div>
      </section>
    }

    <section class="card">
      <h2>{{ 'All shortlists' | t }}</h2>
      @if (loading()) {
        <p class="muted" role="status">{{ 'Loading shortlists…' | t }}</p>
      } @else if (shortlists().length === 0) {
        <div class="empty">
          <p><strong>{{ 'No shortlists yet.' | t }}</strong></p>
          <p class="muted">{{ 'Create one above, or save influencers straight from the search results.' | t }}</p>
          <a class="btn" routerLink="/influencers">{{ 'Find influencers' | t }}</a>
        </div>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Name' | t }}</th>
                <th scope="col" class="num">{{ 'Influencers' | t }}</th>
                <th scope="col">{{ 'Created by' | t }}</th>
                <th scope="col">{{ 'Last changed' | t }}</th>
                @if (canManage) {
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                }
              </tr>
            </thead>
            <tbody>
              @for (shortlist of shortlists(); track shortlist.id) {
                <tr>
                  <td>
                    @if (renaming() === shortlist.id) {
                      <div class="picker">
                        <label class="visually-hidden" [for]="'rename-' + shortlist.id">{{ 'New name' | t }}</label>
                        <input [id]="'rename-' + shortlist.id" type="text" maxlength="120" [value]="renameValue()"
                               (input)="renameValue.set($any($event.target).value)" (keyup.enter)="rename(shortlist)" />
                        <button class="btn btn--primary" type="button" (click)="rename(shortlist)">{{ 'Save name' | t }}</button>
                        <button class="btn" type="button" (click)="renaming.set(null)">{{ 'Cancel' | t }}</button>
                      </div>
                    } @else {
                      <a [routerLink]="['/shortlists', shortlist.id]">{{ shortlist.name }}</a>
                    }
                  </td>
                  <td class="num">{{ shortlist.influencers_count ?? 0 }}</td>
                  <td>{{ shortlist.created_by ?? '–' }}</td>
                  <td>{{ shortlist.updated_at | date: 'mediumDate' }}</td>
                  @if (canManage) {
                    <td class="actions">
                      @if (deleting() === shortlist.id) {
                        <span>{{ 'Delete {name}?' | t: { name: shortlist.name } }}</span>
                        <button class="btn btn--danger" type="button" (click)="remove(shortlist)">{{ 'Delete' | t }}</button>
                        <button class="btn" type="button" (click)="deleting.set(null)">{{ 'Keep' | t }}</button>
                      } @else if (renaming() !== shortlist.id) {
                        <button class="btn btn--quiet" type="button" (click)="startRename(shortlist)">{{ 'Rename' | t }}</button>
                        <button class="btn btn--quiet" type="button" (click)="deleting.set(shortlist.id)">{{ 'Delete' | t }}</button>
                      }
                    </td>
                  }
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </section>
  `,
})
export class Shortlists implements OnInit {
  private readonly api = inject(Api);

  protected readonly canManage = inject(AuthService).can('shortlist.manage');
  protected readonly shortlists = signal<Shortlist[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly newName = signal('');
  protected readonly renaming = signal<number | null>(null);
  protected readonly renameValue = signal('');
  protected readonly deleting = signal<number | null>(null);

  ngOnInit(): void {
    this.api.shortlists().subscribe({
      next: (shortlists) => {
        this.shortlists.set(shortlists);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Shortlists could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  protected create(): void {
    const name = this.newName().trim();
    if (!name) {
      this.error.set('Give the shortlist a name.');
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.api.createShortlist(name).subscribe({
      next: (shortlist) => {
        this.shortlists.update((list) => [shortlist, ...list]);
        this.newName.set('');
        this.busy.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The shortlist could not be created.'));
        this.busy.set(false);
      },
    });
  }

  protected startRename(shortlist: Shortlist): void {
    this.renameValue.set(shortlist.name);
    this.renaming.set(shortlist.id);
  }

  protected rename(shortlist: Shortlist): void {
    const name = this.renameValue().trim();
    if (!name || name === shortlist.name) {
      this.renaming.set(null);
      return;
    }
    this.error.set('');
    this.api.renameShortlist(shortlist.id, name).subscribe({
      next: (updated) => {
        this.shortlists.update((list) => list.map((s) => (s.id === updated.id ? updated : s)));
        this.renaming.set(null);
      },
      error: (err: unknown) => this.error.set(messageFrom(err, 'The shortlist could not be renamed.')),
    });
  }

  protected remove(shortlist: Shortlist): void {
    this.error.set('');
    this.api.deleteShortlist(shortlist.id).subscribe({
      next: () => {
        this.shortlists.update((list) => list.filter((s) => s.id !== shortlist.id));
        this.deleting.set(null);
      },
      error: (err: unknown) => {
        this.deleting.set(null);
        this.error.set(messageFrom(err, 'The shortlist could not be deleted.'));
      },
    });
  }
}
