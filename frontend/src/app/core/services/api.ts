import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AdminOverview, Company, DeviceSession, FilterOptions, Influencer, InfluencerQuery, Invitation, InvitationPreview, Item,
  CreatorAccountInput, CreatorPackageInput, CreatorProfile, Member, Paginated, Role, Shortlist, User,
} from '../models';

/** Typed access to the /api/v1 endpoints that exist in Phase 1. */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  // ---- Account ----
  updateProfile(name: string): Observable<User> {
    return this.http.put<Item<User>>(`${this.base}/auth/profile`, { name }).pipe(map((r) => r.data));
  }

  changePassword(body: { current_password: string; password: string; password_confirmation: string }): Observable<unknown> {
    return this.http.put(`${this.base}/auth/password`, body);
  }

  forgotPassword(email: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/auth/forgot-password`, { email });
  }

  resetPassword(body: { token: string; email: string; password: string; password_confirmation: string }): Observable<unknown> {
    return this.http.post(`${this.base}/auth/reset-password`, body);
  }

  verifyEmail(id: string, hash: string, expires: string, signature: string): Observable<unknown> {
    return this.http.get(`${this.base}/auth/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}`, {
      params: { expires, signature },
    });
  }

  resendVerification(): Observable<unknown> {
    return this.http.post(`${this.base}/auth/email/verification-notification`, {});
  }

  sessions(): Observable<DeviceSession[]> {
    return this.http.get<Item<DeviceSession[]>>(`${this.base}/auth/sessions`).pipe(map((r) => r.data));
  }

  revokeSession(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/auth/sessions/${id}`);
  }

  // ---- Company ----
  company(): Observable<Company> {
    return this.http.get<Item<Company>>(`${this.base}/company`).pipe(map((r) => r.data));
  }

  updateCompany(body: Pick<Company, 'name' | 'website' | 'country' | 'timezone'>): Observable<Company> {
    return this.http.put<Item<Company>>(`${this.base}/company`, body).pipe(map((r) => r.data));
  }

  // ---- Team ----
  members(): Observable<Paginated<Member>> {
    return this.http.get<Paginated<Member>>(`${this.base}/team/members`, { params: { per_page: 100 } });
  }

  roles(): Observable<Role[]> {
    return this.http.get<Item<Role[]>>(`${this.base}/team/roles`).pipe(map((r) => r.data));
  }

  changeRole(userId: number, roleId: number): Observable<Member> {
    return this.http.put<Item<Member>>(`${this.base}/team/members/${userId}`, { role_id: roleId }).pipe(map((r) => r.data));
  }

  removeMember(userId: number): Observable<unknown> {
    return this.http.delete(`${this.base}/team/members/${userId}`);
  }

  invitations(): Observable<Paginated<Invitation>> {
    return this.http.get<Paginated<Invitation>>(`${this.base}/team/invitations`, { params: { per_page: 100 } });
  }

  invite(email: string, roleId: number): Observable<Invitation> {
    return this.http.post<Item<Invitation>>(`${this.base}/team/invitations`, { email, role_id: roleId }).pipe(map((r) => r.data));
  }

  revokeInvitation(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/team/invitations/${id}`);
  }

  invitationPreview(token: string): Observable<InvitationPreview> {
    return this.http
      .get<Item<InvitationPreview>>(`${this.base}/invitations/${encodeURIComponent(token)}`)
      .pipe(map((r) => r.data));
  }

  // ---- Influencer discovery ----
  influencers(query: InfluencerQuery): Observable<Paginated<Influencer>> {
    const params: Record<string, string> = { per_page: '20' };
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params[key] = String(value);
      }
    }
    return this.http.get<Paginated<Influencer>>(`${this.base}/influencers`, { params });
  }

  influencerFilters(): Observable<FilterOptions> {
    return this.http.get<Item<FilterOptions>>(`${this.base}/influencers/filters`).pipe(map((r) => r.data));
  }

  influencer(id: number): Observable<Influencer> {
    return this.http.get<Item<Influencer>>(`${this.base}/influencers/${id}`).pipe(map((r) => r.data));
  }

  compare(ids: number[]): Observable<Influencer[]> {
    return this.http
      .get<Item<Influencer[]>>(`${this.base}/influencers/compare`, { params: { 'ids[]': ids.map(String) } })
      .pipe(map((r) => r.data));
  }

  // ---- Shortlists ----
  shortlists(): Observable<Shortlist[]> {
    return this.http
      .get<Paginated<Shortlist>>(`${this.base}/shortlists`, { params: { per_page: 100 } })
      .pipe(map((r) => r.data));
  }

  shortlist(id: number): Observable<Shortlist> {
    return this.http.get<Item<Shortlist>>(`${this.base}/shortlists/${id}`).pipe(map((r) => r.data));
  }

  createShortlist(name: string): Observable<Shortlist> {
    return this.http.post<Item<Shortlist>>(`${this.base}/shortlists`, { name }).pipe(map((r) => r.data));
  }

  renameShortlist(id: number, name: string): Observable<Shortlist> {
    return this.http.put<Item<Shortlist>>(`${this.base}/shortlists/${id}`, { name }).pipe(map((r) => r.data));
  }

  deleteShortlist(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/shortlists/${id}`);
  }

  addToShortlist(id: number, influencerIds: number[]): Observable<{ added: number; already_in_list: number }> {
    return this.http
      .post<Item<{ added: number; already_in_list: number }>>(`${this.base}/shortlists/${id}/influencers`, {
        influencer_ids: influencerIds,
      })
      .pipe(map((r) => r.data));
  }

  saveShortlistNotes(id: number, influencerId: number, notes: string): Observable<unknown> {
    return this.http.put(`${this.base}/shortlists/${id}/influencers/${influencerId}`, { notes });
  }

  removeFromShortlist(id: number, influencerId: number): Observable<unknown> {
    return this.http.delete(`${this.base}/shortlists/${id}/influencers/${influencerId}`);
  }

  // ---- Creator workspace ----
  creatorProfile(): Observable<CreatorProfile> {
    return this.http.get<Item<CreatorProfile>>(`${this.base}/creator/profile`).pipe(map((r) => r.data));
  }

  updateCreatorProfile(
    body: Pick<CreatorProfile, 'name' | 'category' | 'bio' | 'country' | 'state' | 'city' | 'language'>,
  ): Observable<CreatorProfile> {
    return this.http.put<Item<CreatorProfile>>(`${this.base}/creator/profile`, body).pipe(map((r) => r.data));
  }

  setCreatorListing(listed: boolean): Observable<CreatorProfile> {
    return this.http.put<Item<CreatorProfile>>(`${this.base}/creator/listing`, { listed }).pipe(map((r) => r.data));
  }

  saveCreatorAccount(body: CreatorAccountInput, id?: number): Observable<CreatorProfile> {
    const request = id
      ? this.http.put<Item<CreatorProfile>>(`${this.base}/creator/accounts/${id}`, body)
      : this.http.post<Item<CreatorProfile>>(`${this.base}/creator/accounts`, body);
    return request.pipe(map((r) => r.data));
  }

  deleteCreatorAccount(id: number): Observable<CreatorProfile> {
    return this.http.delete<Item<CreatorProfile>>(`${this.base}/creator/accounts/${id}`).pipe(map((r) => r.data));
  }

  saveCreatorPackage(body: CreatorPackageInput, id?: number): Observable<CreatorProfile> {
    const request = id
      ? this.http.put<Item<CreatorProfile>>(`${this.base}/creator/packages/${id}`, body)
      : this.http.post<Item<CreatorProfile>>(`${this.base}/creator/packages`, body);
    return request.pipe(map((r) => r.data));
  }

  deleteCreatorPackage(id: number): Observable<CreatorProfile> {
    return this.http.delete<Item<CreatorProfile>>(`${this.base}/creator/packages/${id}`).pipe(map((r) => r.data));
  }

  // ---- Platform administration ----
  adminOverview(): Observable<AdminOverview> {
    return this.http.get<Item<AdminOverview>>(`${this.base}/admin/overview`).pipe(map((r) => r.data));
  }

  adminCompanies(search = ''): Observable<Paginated<Company>> {
    return this.http.get<Paginated<Company>>(`${this.base}/admin/companies`, { params: search ? { search } : {} });
  }

  adminSetCompanyStatus(id: number, status: Company['status']): Observable<Company> {
    return this.http.patch<Item<Company>>(`${this.base}/admin/companies/${id}`, { status }).pipe(map((r) => r.data));
  }

  adminUsers(search = ''): Observable<Paginated<User>> {
    return this.http.get<Paginated<User>>(`${this.base}/admin/users`, { params: search ? { search } : {} });
  }
}
