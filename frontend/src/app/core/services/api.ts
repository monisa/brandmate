import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AccessRow, AdminCreator, AdminOverview, AppNotification, ApprovalStatus, BrandCard, BrandFilterOptions, BrandRequest, BulkDeleteResult, ChatMessage, ChatMessages,
  CheckoutDetails, Company, Conversation, CreatorDashboard, CreatorRequest, SubscriptionSummary, DeviceSession, FilterOptions, Influencer,
  InfluencerQuery, Invitation, InvitationPreview, Item, RequestInput, RequestStatus,
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

  updateCompany(body: Pick<Company, 'name' | 'website' | 'country' | 'timezone' | 'phone' | 'channel_name' | 'industry'>): Observable<Company> {
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
    body: Pick<CreatorProfile, 'name' | 'category' | 'bio' | 'country' | 'state' | 'city' | 'language' | 'phone'>,
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

  adminCompanies(search = '', approval: ApprovalStatus | '' = ''): Observable<Paginated<Company>> {
    const params: Record<string, string> = {};
    if (search) {
      params['search'] = search;
    }
    if (approval) {
      params['approval'] = approval;
    }
    return this.http.get<Paginated<Company>>(`${this.base}/admin/companies`, { params });
  }

  adminCreators(search = '', approval: ApprovalStatus | '' = ''): Observable<Paginated<AdminCreator>> {
    const params: Record<string, string> = {};
    if (search) {
      params['search'] = search;
    }
    if (approval) {
      params['approval'] = approval;
    }
    return this.http.get<Paginated<AdminCreator>>(`${this.base}/admin/creators`, { params });
  }

  /** Approves or rejects a brand. A note explains a rejection. */
  adminReviewCompany(id: number, decision: 'approved' | 'rejected', note = ''): Observable<Company> {
    return this.http.post<Item<Company>>(`${this.base}/admin/companies/${id}/review`, { decision, note: note || null }).pipe(map((r) => r.data));
  }

  adminReviewCreator(id: number, decision: 'approved' | 'rejected', note = ''): Observable<AdminCreator> {
    return this.http.post<Item<AdminCreator>>(`${this.base}/admin/creators/${id}/review`, { decision, note: note || null }).pipe(map((r) => r.data));
  }

  adminSetCompanyStatus(id: number, status: Company['status']): Observable<Company> {
    return this.http.patch<Item<Company>>(`${this.base}/admin/companies/${id}`, { status }).pipe(map((r) => r.data));
  }

  adminUsers(search = ''): Observable<Paginated<User>> {
    return this.http.get<Paginated<User>>(`${this.base}/admin/users`, { params: search ? { search } : {} });
  }

  // ---- Requests from brands to creators ----
  /** The signed-in brand's own requests. */
  brandRequests(status: RequestStatus | '' = ''): Observable<Paginated<BrandRequest>> {
    return this.http.get<Paginated<BrandRequest>>(`${this.base}/requests`, { params: status ? { status } : {} });
  }

  sendRequest(input: RequestInput): Observable<BrandRequest> {
    return this.http.post<Item<BrandRequest>>(`${this.base}/requests`, input).pipe(map((r) => r.data));
  }

  withdrawRequest(id: number): Observable<BrandRequest> {
    return this.http.delete<Item<BrandRequest>>(`${this.base}/requests/${id}`).pipe(map((r) => r.data));
  }

  /** The signed-in creator's inbox. `pending_count` counts every unanswered request, whatever the filter. */
  creatorRequests(status: RequestStatus | '' = ''): Observable<Paginated<CreatorRequest> & { pending_count: number }> {
    return this.http.get<Paginated<CreatorRequest> & { pending_count: number }>(`${this.base}/creator/requests`, { params: status ? { status } : {} });
  }

  respondToRequest(id: number, decision: 'accepted' | 'declined', response = ''): Observable<CreatorRequest> {
    return this.http
      .patch<Item<CreatorRequest>>(`${this.base}/creator/requests/${id}`, { decision, response: response || null })
      .pipe(map((r) => r.data));
  }

  // ---- Creator dashboard and notifications ----
  creatorDashboard(): Observable<CreatorDashboard> {
    return this.http.get<Item<CreatorDashboard>>(`${this.base}/creator/dashboard`).pipe(map((r) => r.data));
  }

  notifications(): Observable<{ data: AppNotification[]; unread_count: number }> {
    return this.http.get<{ data: AppNotification[]; unread_count: number }>(`${this.base}/notifications`);
  }

  markNotificationRead(id: string): Observable<{ unread_count: number }> {
    return this.http.post<{ unread_count: number }>(`${this.base}/notifications/${id}/read`, {});
  }

  markAllNotificationsRead(): Observable<{ unread_count: number }> {
    return this.http.post<{ unread_count: number }>(`${this.base}/notifications/read-all`, {});
  }

  /** Permanently deletes several rows at once. Super admin only; the server never deletes super admins or the caller. */
  adminBulkDelete(type: 'users' | 'companies' | 'creators', ids: number[]): Observable<BulkDeleteResult> {
    return this.http.post<Item<BulkDeleteResult>>(`${this.base}/admin/${type}/bulk-delete`, { ids }).pipe(map((r) => r.data));
  }

  // ---- Subscription: Rs 999 + GST a month ----
  subscription(): Observable<SubscriptionSummary> {
    return this.http.get<Item<SubscriptionSummary>>(`${this.base}/subscription`).pipe(map((r) => r.data));
  }

  startCheckout(): Observable<CheckoutDetails> {
    return this.http.post<Item<CheckoutDetails>>(`${this.base}/subscription/checkout`, {}).pipe(map((r) => r.data));
  }

  confirmPayment(payload: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }): Observable<SubscriptionSummary> {
    return this.http.post<Item<SubscriptionSummary>>(`${this.base}/subscription/confirm`, payload).pipe(map((r) => r.data));
  }

  // ---- Super admin: sign-in and subscriptions by hand ----
  adminAccess(search: string): Observable<AccessRow[]> {
    return this.http.get<{ data: AccessRow[] }>(`${this.base}/admin/access`, { params: { search } }).pipe(map((r) => r.data));
  }

  adminSetActive(id: number, active: boolean): Observable<AccessRow> {
    return this.http.patch<Item<AccessRow>>(`${this.base}/admin/access/${id}/active`, { active }).pipe(map((r) => r.data));
  }

  adminSetSubscription(id: number, action: 'grant' | 'revoke', days?: number): Observable<AccessRow> {
    return this.http.post<Item<AccessRow>>(`${this.base}/admin/access/${id}/subscription`, days ? { action, days } : { action }).pipe(map((r) => r.data));
  }

  // ---- Brands, as a creator sees them ----
  brands(query: { q?: string; industry?: string; country?: string; sort?: string; page?: string }): Observable<Paginated<BrandCard>> {
    const params: Record<string, string> = { per_page: '18' };
    for (const [key, value] of Object.entries(query)) {
      if (value) {
        params[key] = value;
      }
    }
    return this.http.get<Paginated<BrandCard>>(`${this.base}/creator/brands`, { params });
  }

  brandFilters(): Observable<BrandFilterOptions> {
    return this.http.get<Item<BrandFilterOptions>>(`${this.base}/creator/brands/filters`).pipe(map((r) => r.data));
  }

  brand(id: number): Observable<BrandCard> {
    return this.http.get<Item<BrandCard>>(`${this.base}/creator/brands/${id}`).pipe(map((r) => r.data));
  }

  // ---- Chat ----
  conversations(): Observable<{ data: Conversation[]; unread_total: number }> {
    return this.http.get<{ data: Conversation[]; unread_total: number }>(`${this.base}/chat/conversations`);
  }

  pushKey(): Observable<{ public_key: string | null }> {
    return this.http.get<{ public_key: string | null }>(`${this.base}/push/key`);
  }

  pushSubscribe(subscription: PushSubscriptionJSON): Observable<unknown> {
    return this.http.post(`${this.base}/push/subscribe`, subscription);
  }

  pushUnsubscribe(endpoint: string): Observable<unknown> {
    return this.http.post(`${this.base}/push/unsubscribe`, { endpoint });
  }

  chatUnread(): Observable<{ unread_total: number }> {
    return this.http.get<{ unread_total: number }>(`${this.base}/chat/unread`);
  }

  startChat(target: { creator_id: number } | { company_id: number }): Observable<Conversation> {
    return this.http.post<Item<Conversation>>(`${this.base}/chat/conversations`, target).pipe(map((r) => r.data));
  }

  /** `after` fetches newer messages; `since` (seconds) also returns older ones that were edited or deleted. */
  chatMessages(id: number, after = 0, since = 0): Observable<ChatMessages> {
    const params: Record<string, number> = {};
    if (after) {
      params['after'] = after;
    }
    if (since) {
      params['since'] = since;
    }
    return this.http.get<ChatMessages>(`${this.base}/chat/conversations/${id}/messages`, { params });
  }

  editChatMessage(id: number, messageId: number, body: string): Observable<ChatMessage> {
    return this.http.patch<Item<ChatMessage>>(`${this.base}/chat/conversations/${id}/messages/${messageId}`, { body }).pipe(map((r) => r.data));
  }

  deleteChatMessage(id: number, messageId: number): Observable<ChatMessage> {
    return this.http.delete<Item<ChatMessage>>(`${this.base}/chat/conversations/${id}/messages/${messageId}`).pipe(map((r) => r.data));
  }

  sendChatMessage(id: number, body: string): Observable<ChatMessage> {
    return this.http.post<Item<ChatMessage>>(`${this.base}/chat/conversations/${id}/messages`, { body }).pipe(map((r) => r.data));
  }
}
