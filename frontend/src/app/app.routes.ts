import { Routes } from '@angular/router';
import {
  approvedCompanyGuard, authGuard, brandHomeGuard, companyGuard, creatorGuard, guestGuard, permissionGuard, superAdminGuard, verifiedGuard,
} from './core/guards/guards';

// Every feature area is lazy loaded.
export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./features/auth/login').then((m) => m.Login) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./features/auth/register').then((m) => m.Register) },
  {
    path: 'register/creator',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/register-creator').then((m) => m.RegisterCreator),
  },
  {
    path: 'forgot-password',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/forgot-password').then((m) => m.ForgotPassword),
  },
  { path: 'oauth/callback', loadComponent: () => import('./features/auth/oauth-callback').then((m) => m.OAuthCallback) },
  { path: 'reset-password', loadComponent: () => import('./features/auth/reset-password').then((m) => m.ResetPassword) },
  { path: 'verify-email', loadComponent: () => import('./features/auth/verify-email').then((m) => m.VerifyEmail) },
  { path: 'accept-invite', loadComponent: () => import('./features/auth/accept-invite').then((m) => m.AcceptInvite) },
  {
    path: '',
    canActivate: [authGuard, verifiedGuard],
    loadComponent: () => import('./layout/shell').then((m) => m.Shell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'creator', canActivate: [creatorGuard], loadComponent: () => import('./features/creator/creator-dashboard').then((m) => m.CreatorDashboardPage) },
      { path: 'creator/profile', canActivate: [creatorGuard], loadComponent: () => import('./features/creator/creator-home').then((m) => m.CreatorHome) },
      {
        path: 'creator/requests',
        canActivate: [creatorGuard],
        loadComponent: () => import('./features/requests/creator-requests').then((m) => m.CreatorRequests),
      },
      { path: 'creator/brands', canActivate: [creatorGuard], loadComponent: () => import('./features/brands/brands').then((m) => m.Brands) },
      { path: 'messages', loadComponent: () => import('./features/messages/messages').then((m) => m.Messages) },
      { path: 'billing', loadComponent: () => import('./features/billing/billing').then((m) => m.Billing) },
      { path: 'dashboard', canActivate: [brandHomeGuard], loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard) },
      {
        path: 'influencers',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('influencer.search')],
        loadComponent: () => import('./features/influencers/search').then((m) => m.InfluencerSearch),
      },
      {
        path: 'influencers/compare',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/influencers/compare').then((m) => m.InfluencerCompare),
      },
      {
        path: 'influencers/:id',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/influencers/profile').then((m) => m.InfluencerProfile),
      },
      {
        path: 'shortlists',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/shortlists/shortlists').then((m) => m.Shortlists),
      },
      {
        path: 'shortlists/:id',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/shortlists/shortlist-detail').then((m) => m.ShortlistDetail),
      },
      {
        path: 'requests',
        canActivate: [companyGuard, approvedCompanyGuard, permissionGuard('request.manage')],
        loadComponent: () => import('./features/requests/brand-requests').then((m) => m.BrandRequests),
      },
      { path: 'team', canActivate: [companyGuard], loadComponent: () => import('./features/team/team').then((m) => m.Team) },
      { path: 'settings', loadChildren: () => import('./features/settings/settings.routes').then((m) => m.settingsRoutes) },
      { path: 'admin', canActivate: [superAdminGuard], loadComponent: () => import('./features/admin/admin').then((m) => m.Admin) },
    ],
  },
  { path: '**', redirectTo: '' },
];
