import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, inject, isDevMode, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideServiceWorker } from '@angular/service-worker';
import { firstValueFrom } from 'rxjs';

import { routes } from './app.routes';
import { AuthService } from './core/auth/auth.service';
import { I18n } from './core/i18n/i18n';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { errorInterceptor } from './core/interceptors/error.interceptor';
import { installPasteCleaning } from './core/paste';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideHttpClient(withInterceptors([authInterceptor, errorInterceptor])),
    provideRouter(routes, withComponentInputBinding()),
    // Restore the session before the first route is resolved, so guards see the user.
    provideAppInitializer(() => firstValueFrom(inject(AuthService).restore())),
    // Makes the app installable and quick to reopen. Production builds only; it never
    // caches API calls (see ngsw-config.json), so data is always live.
    provideServiceWorker('ngsw-worker.js', {
      enabled: !isDevMode(),
      registrationStrategy: 'registerWhenStable:30000',
    }),
    // Pasting into any field trims spaces and cleans numbers like "12,500".
    provideAppInitializer(() => installPasteCleaning()),
    // Load the saved language before anything is drawn, so there is no flash of English.
    provideAppInitializer(() => inject(I18n).init()),
  ],
};
