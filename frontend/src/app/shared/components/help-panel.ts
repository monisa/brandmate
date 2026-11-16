import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { TPipe } from '../../core/i18n/t.pipe';
import { FAQS, Faq } from '../../features/support/support-faq';

/** A "Help" button that opens a searchable list of questions and answers, with a way to raise a ticket. */
@Component({
  selector: 'app-help',
  imports: [TPipe, RouterLink],
  template: `
    <button class="help__fab" type="button" (click)="open.set(!open())" [attr.aria-expanded]="open()" aria-controls="help-panel">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01" /></svg>
      {{ 'Help' | t }}
    </button>

    @if (open()) {
      <section class="help" id="help-panel" role="dialog" [attr.aria-label]="'Help' | t" (keydown.escape)="open.set(false)">
        <header class="help__head">
          <strong>{{ 'How can we help?' | t }}</strong>
          <button class="btn btn--quiet" type="button" (click)="open.set(false)" [attr.aria-label]="'Close' | t">×</button>
        </header>

        <input class="help__search" type="search" [value]="query()" (input)="query.set($any($event.target).value)"
               [attr.placeholder]="'Search questions' | t" [attr.aria-label]="'Search questions' | t" />

        <ul class="help__list">
          @for (faq of results(); track faq.id) {
            <li>
              <button class="help__q" type="button" [attr.aria-expanded]="openId() === faq.id" (click)="openId.set(openId() === faq.id ? '' : faq.id)">
                {{ faq.question | t }}
              </button>
              @if (openId() === faq.id) {
                <div class="help__a">
                  <p>{{ faq.answer | t }}</p>
                  @if (faq.link; as link) {
                    <a [routerLink]="link.route" (click)="open.set(false)">{{ link.label | t }} →</a>
                  }
                </div>
              }
            </li>
          } @empty {
            <li class="help__none">{{ 'No answer found for that.' | t }}</li>
          }
        </ul>

        <footer class="help__foot">
          <p>{{ 'Still not solved?' | t }}</p>
          <a class="btn btn--primary" routerLink="/support" (click)="open.set(false)">{{ 'Raise a ticket' | t }}</a>
        </footer>
      </section>
    }
  `,
})
export class HelpPanel {
  private readonly auth = inject(AuthService);

  protected readonly open = signal(false);
  protected readonly query = signal('');
  protected readonly openId = signal('');

  /** Only the questions that apply to this kind of account: brands and creators use different things. */
  private readonly mine = computed<Faq[]>(() => {
    const audience = this.auth.user()?.account_type === 'creator' ? 'creator' : 'brand';

    return FAQS.filter((f) => f.audience === 'all' || f.audience === audience);
  });

  protected readonly results = computed<Faq[]>(() => {
    const terms = this.query().toLowerCase().split(/\s+/).filter((t) => t.length > 1);
    if (terms.length === 0) {
      return this.mine();
    }

    return this.mine()
      .map((faq) => {
        const text = `${faq.question} ${faq.answer} ${faq.words.join(' ')}`.toLowerCase();

        return { faq, score: terms.filter((t) => text.includes(t)).length };
      })
      .filter((m) => m.score > 0)
      .sort((a, b) => b.score - a.score)
      .map((m) => m.faq);
  });
}
