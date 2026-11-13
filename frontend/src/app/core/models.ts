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
  company: { id: number; name: string; slug: string } | null;
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
  status: 'active' | 'suspended';
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
  listed: boolean;
  missing_for_listing: string[];
  category_options: string[];
  currency: string;
  accounts: CreatorAccount[];
  packages: CreatorPackage[];
}

export type CreatorAccountInput = Pick<CreatorAccount, 'platform' | 'username' | 'profile_url'> & { followers: number };
export type CreatorPackageInput = Pick<CreatorPackage, 'platform' | 'title' | 'description' | 'price' | 'delivery_days' | 'is_active'>;
