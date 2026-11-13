import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { CheckoutDetails, SubscriptionSummary } from '../../core/models';
import { Api } from '../../core/services/api';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';

interface RazorpaySuccess {
  razorpay_order_id: string;
  razorpay_payment_id: string;
  razorpay_signature: string;
}

interface RazorpayInstance {
  open(): void;
  on(event: string, handler: (response: { error?: { description?: string } }) => void): void;
}

declare global {
  interface Window {
    Razorpay?: new (options: Record<string, unknown>) => RazorpayInstance;
  }
}

const CHECKOUT_SCRIPT = 'https://checkout.razorpay.com/v1/checkout.js';

/** Razorpay's Checkout script, loaded only when someone is about to pay. */
function loadCheckout(): Promise<void> {
  if (window.Razorpay) {
    return Promise.resolve();
  }
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = CHECKOUT_SCRIPT;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Checkout could not be loaded'));
    document.head.appendChild(script);
  });
}

/** One month for Rs 999 plus GST, paid by hand each month. It unlocks phone numbers, e-mail, channel names, full profiles and chat. */
@Component({
  selector: 'app-billing',
  imports: [TPipe, CurrencyPipe, DatePipe],
  template: `
    <header class="page-head">
      <h1>{{ 'Subscription' | t }}</h1>
      <p class="muted">{{ audienceText() }}</p>
    </header>

    @if (notice()) {
      <p class="alert alert--success" role="status">{{ notice() | t }}</p>
    }
    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading…' | t }}</p>
    } @else if (summary(); as s) {
      @if (!s.enforced) {
        <p class="alert alert--info">{{ 'Subscriptions are not being charged yet, so everything is unlocked for everyone.' | t }}</p>
      }

      <div class="columns">
        <section class="card plan" aria-labelledby="plan-title">
          <h2 id="plan-title">{{ 'Monthly plan' | t }}</h2>
          <p class="plan__price">
            <strong>{{ s.plan.price | currency: s.plan.currency : 'symbol' : '1.0-0' }}</strong>
            <span class="muted"> {{ 'per month' | t }} + {{ s.plan.gst_percent }}% GST</span>
          </p>
          <dl class="plan__split">
            <div><dt>{{ 'Price' | t }}</dt><dd>{{ s.plan.price | currency: s.plan.currency : 'symbol' : '1.2-2' }}</dd></div>
            <div><dt>GST ({{ s.plan.gst_percent }}%)</dt><dd>{{ s.plan.gst | currency: s.plan.currency : 'symbol' : '1.2-2' }}</dd></div>
            <div class="plan__total"><dt>{{ 'You pay for one month' | t }}</dt><dd>{{ s.plan.total | currency: s.plan.currency : 'symbol' : '1.2-2' }}</dd></div>
          </dl>
          <ul class="plan__list">
            <li>{{ 'See phone numbers, e-mail addresses and channel names' | t }}</li>
            <li>{{ 'Open full profiles' | t }}</li>
            <li>{{ 'Chat with brands and creators' | t }}</li>
            <li>{{ 'No automatic charges. Pay again each month to keep access.' | t }}</li>
          </ul>
        </section>

        <section class="card" aria-labelledby="status-title">
          <h2 id="status-title">{{ 'Your subscription' | t }}</h2>

          @switch (state()) {
            @case ('active') {
              <p><span class="badge badge--ok">{{ 'Active' | t }}</span></p>
              <p>{{ 'Active until {date}.' | t: { date: dateText(s.current_period_end) } }}</p>
              @if (endingSoon()) {
                <p class="alert alert--info">{{ 'Your month ends soon. Pay for the next month to keep your details unlocked.' | t }}</p>
              }
            }
            @case ('expired') {
              <p><span class="badge badge--bad">{{ 'Not active' | t }}</span></p>
              <p>{{ 'Your month has ended. Pay again to unlock details.' | t }}</p>
            }
            @default {
              <p><span class="badge">{{ 'Not subscribed' | t }}</span></p>
              <p>{{ 'Contact details are hidden until you subscribe.' | t }}</p>
            }
          }

          @if (s.can_manage) {
            @if (s.payments_ready) {
              <button class="btn btn--primary" type="button" [disabled]="busy()" (click)="subscribe()">
                {{ (busy() ? 'Opening payment…' : state() === 'active' ? 'Pay for next month' : 'Subscribe') | t }} · {{ s.plan.total | currency: s.plan.currency : 'symbol' : '1.2-2' }}
              </button>
              <p class="field-hint">{{ 'One payment buys one month. You pay securely with Razorpay: UPI, cards or net banking. GST invoices are listed below.' | t }}</p>
            } @else {
              <p class="alert alert--info">{{ 'Payments are not set up yet. Please check back soon.' | t }}</p>
            }
          } @else {
            <p class="muted">{{ 'Your company admin manages the subscription for the whole team.' | t }}</p>
          }
        </section>
      </div>

      <section class="card" aria-labelledby="invoices-title">
        <h2 id="invoices-title">{{ 'Invoices' | t }}</h2>
        @if (s.payments.length === 0) {
          <p class="muted">{{ 'No payments yet.' | t }}</p>
        } @else {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ 'Invoice' | t }}</th>
                  <th scope="col">{{ 'Date' | t }}</th>
                  <th scope="col" class="num">{{ 'Price' | t }}</th>
                  <th scope="col" class="num">GST</th>
                  <th scope="col" class="num">{{ 'Total' | t }}</th>
                </tr>
              </thead>
              <tbody>
                @for (invoice of s.payments; track invoice.id) {
                  <tr>
                    <td>{{ invoice.invoice_number ?? '–' }}</td>
                    <td>{{ invoice.paid_at | date: 'mediumDate' }}</td>
                    <td class="num">{{ invoice.price | currency: invoice.currency : 'symbol' : '1.2-2' }}</td>
                    <td class="num">{{ invoice.gst | currency: invoice.currency : 'symbol' : '1.2-2' }}</td>
                    <td class="num"><strong>{{ invoice.total | currency: invoice.currency : 'symbol' : '1.2-2' }}</strong></td>
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
export class Billing implements OnInit {
  private readonly api = inject(Api);
  private readonly i18n = inject(I18n);
  private readonly isCreator = inject(AuthService).user()?.account_type === 'creator';

  protected readonly summary = signal<SubscriptionSummary | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');

  /** One word for the screen to switch on. */
  protected readonly state = computed(() => {
    const s = this.summary();
    if (!s || s.status === 'none' || s.status === 'created') {
      return 'none';
    }
    return s.active ? 'active' : 'expired';
  });

  /** True in the last five days of a paid month, so the next payment can be made in time. */
  protected readonly endingSoon = computed(() => {
    const end = this.summary()?.current_period_end;
    return !!end && new Date(end).getTime() - Date.now() < 5 * 24 * 3600 * 1000;
  });

  protected audienceText(): string {
    return this.i18n.t(
      this.isCreator
        ? 'Subscribe to see brands’ contact details, open their profiles and chat with them.'
        : 'Subscribe once for your whole team to see creators’ contact details, open their profiles and chat with them.',
    );
  }

  protected dateText(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '–';
  }

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.api.subscription().subscribe({
      next: (summary) => {
        this.summary.set(summary);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Your subscription could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  protected async subscribe(): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');

    try {
      const checkout = await new Promise<CheckoutDetails>((resolve, reject) =>
        this.api.startCheckout().subscribe({ next: resolve, error: reject }),
      );
      await loadCheckout();
      this.open(checkout);
    } catch (err) {
      this.error.set(messageFrom(err, 'The payment could not be started. Please try again.'));
      this.busy.set(false);
    }
  }

  private open(checkout: CheckoutDetails): void {
    const Razorpay = window.Razorpay;
    if (!Razorpay) {
      this.error.set('The payment window could not be opened. Please try again.');
      this.busy.set(false);
      return;
    }

    const rzp = new Razorpay({
      key: checkout.key_id,
      order_id: checkout.order_id,
      amount: checkout.amount,
      currency: checkout.currency,
      name: checkout.name,
      description: checkout.description,
      prefill: checkout.prefill,
      theme: { color: '#c8245c' },
      // Razorpay calls this after a payment. The server checks its signature before unlocking anything.
      handler: (response: RazorpaySuccess) => this.confirm(response),
      modal: { ondismiss: () => this.busy.set(false) },
    });
    rzp.on('payment.failed', (response) => {
      this.error.set(response.error?.description ?? 'The payment did not go through.');
      this.busy.set(false);
    });
    rzp.open();
  }

  private confirm(response: RazorpaySuccess): void {
    this.api.confirmPayment(response).subscribe({
      next: (summary) => {
        this.summary.set(summary);
        this.notice.set('Payment received. Your details are unlocked for a month.');
        this.busy.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'We could not confirm the payment yet. If money was taken, it will appear here shortly.'));
        this.busy.set(false);
        this.load();
      },
    });
  }
}
