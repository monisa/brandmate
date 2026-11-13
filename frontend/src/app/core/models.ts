/** Where a brand or creator stands with the platform team. */
export type ApprovalStatus = 'pending' | 'approved' | 'rejected';

export interface RoleRef {
  id: number;
  name: string;
  slug: string;
}

export interface Role extends RoleRef {
  description: string | null;
  permissions: string[];
}

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified: boolean;
  is_super_admin: boolean;
  account_type: 'brand' | 'creator';
  company: { id: number; name: string; slug: string; approval_status: ApprovalStatus; review_note: string | null } | null;
  role: RoleRef | null;
  /** For showing or hiding UI only. The API enforces permissions itself. */
  permissions: string[];
}

export interface Session {
  token: string;
  expires_at: string | null;
  user: User;
}

export interface Member {
  id: number;
  name: string;
  email: string;
  role: RoleRef;
  status: string;
  joined_at: string | null;
}

export interface Invitation {
  id: number;
  email: string;
  role: RoleRef;
  invited_by: string | null;
  expires_at: string;
  created_at: string;
}

export interface InvitationPreview {
  email: string;
  company: string;
  role: string;
}

export interface Company {
  id: number;
  name: string;
  slug: string;
  website: string | null;
  country: string | null;
  timezone: string;
  phone?: string | null;
  channel_name?: string | null;
  industry?: string | null;
  status: 'active' | 'suspended';
  approval_status: ApprovalStatus;
  review_note: string | null;
  reviewed_at: string | null;
  /** The first member, shown while reviewing a new company. */
  owner?: { name: string; email: string } | null;
  members_count?: number;
  created_at: string;
}

export interface DeviceSession {
  id: number;
  device: string;
  current: boolean;
  last_used_at: string | null;
  created_at: string | null;
  expires_at: string | null;
}

export interface AdminOverview {
  companies: number;
  suspended_companies: number;
  users: number;
  unverified_users: number;
  pending_companies: number;
  pending_creators: number;
}

/** A creator who signed up themselves, as the platform team sees them. */
export interface AdminCreator {
  id: number;
  name: string;
  email: string | null;
  category: string | null;
  country: string | null;
  listed: boolean;
  approval_status: ApprovalStatus;
  review_note: string | null;
  reviewed_at: string | null;
  created_at: string | null;
  accounts: { platform: Platform; username: string; followers: number | null }[];
}

export interface Item<T> {
  data: T;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export type Platform = 'instagram' | 'youtube' | 'facebook';

/** One platform account of a creator. A null metric means the data is unavailable. */
export interface Influencer {
  id: number;
  creator_id: number;
  name: string;
  username: string;
  platform: Platform;
  profile_url: string | null;
  avatar_url: string | null;
  bio: string | null;
  category: string | null;
  country: string | null;
  state: string | null;
  city: string | null;
  language: string | null;
  is_verified: boolean | null;
  followers: number | null;
  following: number | null;
  engagement_rate: number | null;
  avg_likes: number | null;
  avg_comments: number | null;
  avg_views: number | null;
  is_registered_creator: boolean;
  /** True when the viewer has no subscription: the channel name and contact details below are masked. */
  locked?: boolean;
  contact?: { email: string | null; phone: string | null };
  packages?: CreatorPackage[];
  data_source: string;
  provider: string;
  sync_status: string;
  last_updated_at: string | null;
  other_platforms?: { id: number; platform: Platform; username: string; followers: number | null }[];
}

export interface FilterOptions {
  platforms: Platform[];
  categories: string[];
  countries: string[];
}

export interface InfluencerQuery {
  q?: string;
  platform?: string;
  category?: string;
  country?: string;
  followers_min?: string;
  followers_max?: string;
  engagement_min?: string;
  engagement_max?: string;
  verified?: string;
  registered?: string;
  sort?: string;
  direction?: string;
  page?: string;
}

export interface ShortlistItem {
  influencer: Influencer;
  notes: string | null;
  added_at: string;
}

export interface Shortlist {
  id: number;
  name: string;
  influencers_count?: number;
  created_by?: string | null;
  created_at: string;
  updated_at: string;
  items?: ShortlistItem[];
}

export interface CreatorPackage {
  id: number;
  platform: Platform;
  title: string;
  description: string | null;
  /** Whole currency units, e.g. rupees. */
  price: number;
  currency: string;
  delivery_days: number;
  is_active: boolean;
}

export interface CreatorAccount {
  id: number;
  platform: Platform;
  username: string;
  profile_url: string;
  followers: number | null;
  /** False while the numbers are only what the creator typed in. */
  verified_data: boolean;
}

/** A creator's own view of their profile. */
export interface CreatorProfile {
  id: number;
  name: string;
  category: string | null;
  bio: string | null;
  country: string | null;
  state: string | null;
  city: string | null;
  language: string | null;
  phone?: string | null;
  email?: string | null;
  listed: boolean;
  approval_status: ApprovalStatus;
  review_note: string | null;
  pending_requests: number;
  missing_for_listing: string[];
  category_options: string[];
  currency: string;
  accounts: CreatorAccount[];
  packages: CreatorPackage[];
}

export type CreatorAccountInput = Pick<CreatorAccount, 'platform' | 'username' | 'profile_url'> & { followers: number };
export type CreatorPackageInput = Pick<CreatorPackage, 'platform' | 'title' | 'description' | 'price' | 'delivery_days' | 'is_active'>;

export type RequestStatus = 'pending' | 'accepted' | 'declined' | 'withdrawn';

interface RequestBase {
  id: number;
  title: string;
  message: string;
  /** Whole currency units. Null when the brand did not name a budget. */
  budget: number | null;
  currency: string;
  due_date: string | null;
  status: RequestStatus;
  creator_response: string | null;
  responded_at: string | null;
  created_at: string;
  package: { id: number; title: string; price: number; currency: string } | null;
}

/** A request as the brand that sent it sees it. */
export interface BrandRequest extends RequestBase {
  creator: {
    id: number;
    profile_id: number | null;
    name: string;
    username: string | null;
    platform: Platform | null;
    avatar_url: string | null;
    /** Only after the creator accepted. */
    email: string | null;
  };
  requested_by: string | null;
}

/** A request as the creator who received it sees it. */
export interface CreatorRequest extends RequestBase {
  /** True when the creator has no subscription: the text is hidden and answering is disabled. */
  locked?: boolean;
  brand: {
    company_id?: number | null;
    company: string | null;
    requested_by: string | null;
    /** Only after the creator accepted. */
    email: string | null;
  };
}

export interface RequestInput {
  creator_id: number;
  package_id: number | null;
  title: string;
  message: string;
  budget: number | null;
  due_date: string | null;
}

export type DashboardAction =
  | { type: 'answer_requests'; count: number }
  | { type: 'awaiting_approval' }
  | { type: 'approval_rejected'; note: string | null }
  | { type: 'complete_profile'; items: string[] }
  | { type: 'list_profile' }
  | { type: 'add_package' }
  | { type: 'add_bio' };

/** What a creator sees first: profile visits, requests and what to do next. */
export interface CreatorDashboard {
  approval_status: ApprovalStatus;
  review_note: string | null;
  listed: boolean;
  visible_to_brands: boolean;
  views: {
    last_7_days: number;
    last_30_days: number;
    brands_30_days: number;
    /** One entry per day for the last 14 days, oldest first. */
    series: { date: string; count: number }[];
  };
  recent_visitors: { company: string | null; at: string }[];
  requests: { pending: number; accepted: number; declined: number };
  actions: DashboardAction[];
}

/** The API sends a kind and a few values; the app words it so it can be translated. */
export interface AppNotification {
  id: string;
  kind: string;
  meta: Record<string, string | number | null>;
  link: string | null;
  read: boolean;
  created_at: string;
}

export interface BulkDeleteResult {
  users?: number;
  companies?: number;
  creators?: number;
  skipped: number;
}

/** Rs 999 + GST a month. Amounts are in whole rupees. */
export interface SubscriptionPlan {
  price: number;
  gst_percent: number;
  gst: number;
  total: number;
  currency: string;
  interval: 'month';
}

export interface SubscriptionInvoice {
  id: number;
  invoice_number: string | null;
  paid_at: string;
  price: number;
  gst: number;
  total: number;
  currency: string;
  status: string;
}

export interface SubscriptionSummary {
  /** False while the platform is not charging yet: nothing is locked. */
  enforced: boolean;
  /** Whether this person can see contact details right now. */
  active: boolean;
  status: 'none' | 'created' | 'active' | 'past_due' | 'cancelled' | 'expired';
  gateway: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  can_manage: boolean;
  /** False until Razorpay keys are set on the server. */
  payments_ready: boolean;
  plan: SubscriptionPlan;
  payments: SubscriptionInvoice[];
}

export interface CheckoutDetails {
  key_id: string;
  order_id: string;
  amount: number;
  currency: string;
  name: string;
  description: string;
  prefill: { name: string; email: string; contact: string | null };
}

/** A brand as a creator sees it. Contact details are masked until the creator subscribes. */
export interface BrandCard {
  id: number;
  name: string;
  industry: string | null;
  country: string | null;
  locked: boolean;
  contact: { email: string | null; phone: string | null; channel_name: string | null; website: string | null };
}

export interface BrandFilterOptions {
  industries: string[];
  countries: string[];
}

export interface Conversation {
  id: number;
  counterpart: { kind: 'creator' | 'brand'; id: number; name: string; avatar_url: string | null };
  unread_count: number;
  last_message: { body: string; from_me: boolean } | null;
  last_message_at: string | null;
}

export interface ChatMessage {
  id: number;
  body: string;
  from_me: boolean;
  sender: string | null;
  created_at: string;
}

export interface ChatMessages {
  data: ChatMessage[];
  meta: { can_send: boolean; reason: 'subscription_required' | 'counterpart_not_subscribed' | null };
}

/** A person found by the super admin, with what they can currently do. */
export interface AccessRow {
  id: number;
  name: string;
  email: string;
  kind: 'creator' | 'brand';
  company: string | null;
  deactivated: boolean;
  subscription: { active: boolean; status: string; until: string | null };
}
