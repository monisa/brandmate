import { Routes } from '@angular/router';
import { companyGuard } from '../../core/guards/guards';

export const settingsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./settings').then((m) => m.Settings),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'profile' },
      { path: 'profile', loadComponent: () => import('./profile').then((m) => m.Profile) },
      { path: 'security', loadComponent: () => import('./security').then((m) => m.Security) },
      { path: 'company', canActivate: [companyGuard], loadComponent: () => import('./company').then((m) => m.CompanySettings) },
    ],
  },
];
