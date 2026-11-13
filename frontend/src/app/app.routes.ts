import { Routes } from '@angular/router';
import {
  authGuard, brandHomeGuard, companyGuard, creatorGuard, guestGuard, permissionGuard, superAdminGuard, verifiedGuard,
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
  { path: 'reset-password', loadComponent: () => import('./features/auth/reset-password').then((m) => m.ResetPassword) },
  { path: 'verify-email', loadComponent: () => import('./features/auth/verify-email').then((m) => m.VerifyEmail) },
  { path: 'accept-invite', loadComponent: () => import('./features/auth/accept-invite').then((m) => m.AcceptInvite) },
  {
    path: '',
    canActivate: [authGuard, verifiedGuard],
    loadComponent: () => import('./layout/shell').then((m) => m.Shell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'creator', canActivate: [creatorGuard], loadComponent: () => import('./features/creator/creator-home').then((m) => m.CreatorHome) },
      { path: 'dashboard', canActivate: [brandHomeGuard], loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard) },
      {
        path: 'influencers',
        canActivate: [companyGuard, permissionGuard('influencer.search')],
        loadComponent: () => import('./features/influencers/search').then((m) => m.InfluencerSearch),
      },
      {
        path: 'influencers/compare',
        canActivate: [companyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/influencers/compare').then((m) => m.InfluencerCompare),
      },
      {
        path: 'influencers/:id',
        canActivate: [companyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/influencers/profile').then((m) => m.InfluencerProfile),
      },
      {
        path: 'shortlists',
        canActivate: [companyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/shortlists/shortlists').then((m) => m.Shortlists),
      },
      {
        path: 'shortlists/:id',
        canActivate: [companyGuard, permissionGuard('influencer.view')],
        loadComponent: () => import('./features/shortlists/shortlist-detail').then((m) => m.ShortlistDetail),
      },
      { path: 'team', canActivate: [companyGuard], loadComponent: () => import('./features/team/team').then((m) => m.Team) },
      { path: 'settings', loadChildren: () => import('./features/settings/settings.routes').then((m) => m.settingsRoutes) },
      { path: 'admin', canActivate: [superAdminGuard], loadComponent: () => import('./features/admin/admin').then((m) => m.Admin) },
    ],
  },
  { path: '**', redirectTo: '' },
];
