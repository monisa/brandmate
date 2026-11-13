import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { applyServerErrors, messageFrom } from '../../core/forms';
import { Invitation, Member, Role } from '../../core/models';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-team',
  imports: [TPipe, ReactiveFormsModule, DatePipe, FieldError],
  template: `
    <header class="page-head">
      <h1>{{ 'Team' | t }}</h1>
      <p class="muted">{{ 'People who can work in {company}.' | t: { company: auth.user()?.company?.name } }}</p>
    </header>

    @if (notice()) {
      <p class="alert alert--success" role="status">{{ notice() | t }}</p>
    }
    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (canManage) {
      <section class="card">
        <h2>{{ 'Invite a team member' | t }}</h2>
        <form class="inline-form" [formGroup]="inviteForm" (ngSubmit)="invite()" novalidate>
          <div class="field">
            <label for="invite-email">{{ 'Email' | t }}</label>
            <input id="invite-email" type="email" formControlName="email" autocomplete="off" />
            <app-field-error [control]="inviteForm.controls.email" />
          </div>
          <div class="field">
            <label for="invite-role">{{ 'Role' | t }}</label>
            <select id="invite-role" formControlName="role_id">
              @for (role of roles(); track role.id) {
                <option [ngValue]="role.id">{{ role.name | t }}</option>
              }
            </select>
          </div>
          <button class="btn btn--primary" type="submit" [disabled]="inviting()">
            {{ (inviting() ? 'Sending…' : 'Send invitation') | t }}
          </button>
        </form>
      </section>
    }

    <section class="card">
      <h2>{{ 'Members' | t }}</h2>
      @if (loading()) {
        <p class="muted" role="status">{{ 'Loading members…' | t }}</p>
      } @else if (loadFailed()) {
        <p class="alert alert--error" role="alert">{{ 'The team list could not be loaded.' | t }}</p>
        <button class="btn" type="button" (click)="load()">{{ 'Try again' | t }}</button>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Name' | t }}</th>
                <th scope="col">{{ 'Email' | t }}</th>
                <th scope="col">{{ 'Role' | t }}</th>
                <th scope="col">{{ 'Joined' | t }}</th>
                @if (canManage) {
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                }
              </tr>
            </thead>
            <tbody>
              @for (member of members(); track member.id) {
                <tr>
                  <td>
                    {{ member.name }}
                    @if (member.id === auth.user()?.id) {
                      <span class="badge">{{ 'You' | t }}</span>
                    }
                  </td>
                  <td>{{ member.email }}</td>
                  <td>
                    @if (canManage) {
                      <select [attr.aria-label]="'Role for {name}' | t: { name: member.name }" [value]="member.role.id"
                              (change)="changeRole(member, $any($event.target).value)">
                        @for (role of roles(); track role.id) {
                          <option [value]="role.id" [selected]="role.id === member.role.id">{{ role.name | t }}</option>
                        }
                      </select>
                    } @else {
                      {{ member.role.name | t }}
                    }
                  </td>
                  <td>{{ member.joined_at ? (member.joined_at | date: 'mediumDate') : '–' }}</td>
                  @if (canManage) {
                    <td class="actions">
                      @if (member.id !== auth.user()?.id) {
                        @if (confirmingRemove() === member.id) {
                          <span>{{ 'Remove {name}?' | t: { name: member.name } }}</span>
                          <button class="btn btn--danger" type="button" (click)="remove(member)">{{ 'Remove' | t }}</button>
                          <button class="btn" type="button" (click)="confirmingRemove.set(null)">{{ 'Keep' | t }}</button>
                        } @else {
                          <button class="btn btn--quiet" type="button" (click)="confirmingRemove.set(member.id)">{{ 'Remove' | t }}</button>
                        }
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

    @if (canManage) {
      <section class="card">
        <h2>{{ 'Pending invitations' | t }}</h2>
        @if (invitations().length === 0) {
          <p class="muted">{{ 'No one is waiting to join. Invitations you send appear here until they are accepted.' | t }}</p>
        } @else {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ 'Email' | t }}</th>
                  <th scope="col">{{ 'Role' | t }}</th>
                  <th scope="col">{{ 'Expires' | t }}</th>
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                </tr>
              </thead>
              <tbody>
                @for (invitation of invitations(); track invitation.id) {
                  <tr>
                    <td>{{ invitation.email }}</td>
                    <td>{{ invitation.role.name | t }}</td>
                    <td>{{ invitation.expires_at | date: 'mediumDate' }}</td>
                    <td class="actions">
                      <button class="btn btn--quiet" type="button" (click)="revoke(invitation)">{{ 'Revoke' | t }}</button>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    }
  `,
})
export class Team implements OnInit {
  private readonly i18n = inject(I18n);
  protected readonly auth = inject(AuthService);
  private readonly api = inject(Api);

  protected readonly canManage = this.auth.can('team.manage');
  protected readonly members = signal<Member[]>([]);
  protected readonly roles = signal<Role[]>([]);
  protected readonly invitations = signal<Invitation[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadFailed = signal(false);
  protected readonly inviting = signal(false);
  protected readonly confirmingRemove = signal<number | null>(null);
  protected readonly notice = signal('');
  protected readonly error = signal('');

  protected readonly inviteForm = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    role_id: [0, Validators.min(1)],
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.loadFailed.set(false);

    this.api.members().subscribe({
      next: (res) => {
        this.members.set(res.data);
        this.loading.set(false);
      },
      error: () => {
        this.loadFailed.set(true);
        this.loading.set(false);
      },
    });

    this.api.roles().subscribe((roles) => {
      this.roles.set(roles);
      const fallback = roles.find((role) => role.slug === 'team_member') ?? roles[0];
      if (fallback && !this.inviteForm.controls.role_id.value) {
        this.inviteForm.controls.role_id.setValue(fallback.id);
      }
    });

    if (this.canManage) {
      this.api.invitations().subscribe((res) => this.invitations.set(res.data));
    }
  }

  protected invite(): void {
    if (this.inviteForm.invalid) {
      this.inviteForm.markAllAsTouched();
      return;
    }
    const { email, role_id } = this.inviteForm.getRawValue();
    this.inviting.set(true);
    this.say('', '');

    this.api.invite(email, role_id).subscribe({
      next: (invitation) => {
        this.invitations.update((list) => [invitation, ...list.filter((i) => i.email !== invitation.email)]);
        this.inviteForm.controls.email.reset('');
        this.say(this.i18n.t('Invitation sent to {email}.', { email: invitation.email }), '');
        this.inviting.set(false);
      },
      error: (err: unknown) => {
        if (!applyServerErrors(this.inviteForm, err)) {
          this.say('', messageFrom(err, 'The invitation could not be sent.'));
        }
        this.inviting.set(false);
      },
    });
  }

  protected changeRole(member: Member, value: string): void {
    const roleId = Number(value);
    if (roleId === member.role.id) {
      return;
    }
    this.say('', '');
    this.api.changeRole(member.id, roleId).subscribe({
      next: (updated) => {
        this.members.update((list) => list.map((m) => (m.id === updated.id ? updated : m)));
        this.say(this.i18n.t('{name} is now {role}.', { name: updated.name, role: this.i18n.t(updated.role.name) }), '');
        if (updated.id === this.auth.user()?.id) {
          this.auth.refresh().subscribe(); // own permissions changed
        }
      },
      error: (err: unknown) => {
        this.members.update((list) => [...list]); // re-render the select with the old role
        this.say('', messageFrom(err, 'The role could not be changed.'));
      },
    });
  }

  protected remove(member: Member): void {
    this.say('', '');
    this.api.removeMember(member.id).subscribe({
      next: () => {
        this.members.update((list) => list.filter((m) => m.id !== member.id));
        this.confirmingRemove.set(null);
        this.say(this.i18n.t('{name} was removed.', { name: member.name }), '');
      },
      error: (err: unknown) => {
        this.confirmingRemove.set(null);
        this.say('', messageFrom(err, 'The member could not be removed.'));
      },
    });
  }

  protected revoke(invitation: Invitation): void {
    this.say('', '');
    this.api.revokeInvitation(invitation.id).subscribe({
      next: () => {
        this.invitations.update((list) => list.filter((i) => i.id !== invitation.id));
        this.say(this.i18n.t('Invitation for {email} revoked.', { email: invitation.email }), '');
      },
      error: (err: unknown) => this.say('', messageFrom(err, 'The invitation could not be revoked.')),
    });
  }

  private say(notice: string, error: string): void {
    this.notice.set(notice);
    this.error.set(error);
  }
}
