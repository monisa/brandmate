import { Component, OnInit, inject, input, output, signal } from '@angular/core';
import { messageFrom } from '../../core/forms';
import { Shortlist } from '../../core/models';
import { Api } from '../../core/services/api';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';

const NEW = 'new';

/** "Add to shortlist" control: pick an existing shortlist or name a new one. */
@Component({
  selector: 'app-shortlist-picker',
  imports: [TPipe],
  template: `
    <div class="picker">
      <label class="visually-hidden" [for]="fieldId">{{ 'Shortlist' | t }}</label>
      <select [id]="fieldId" [value]="choice()" (change)="choice.set($any($event.target).value)">
        <option value="">{{ 'Choose a shortlist' | t }}</option>
        @for (shortlist of shortlists(); track shortlist.id) {
          <option [value]="shortlist.id">{{ shortlist.name }}</option>
        }
        <option [value]="newValue">{{ 'New shortlist…' | t }}</option>
      </select>
      @if (choice() === newValue) {
        <label class="visually-hidden" [for]="fieldId + '-name'">{{ 'Name of the new shortlist' | t }}</label>
        <input [id]="fieldId + '-name'" type="text" [placeholder]="'Shortlist name' | t" maxlength="120"
               [value]="newName()" (input)="newName.set($any($event.target).value)" (keyup.enter)="add()" />
      }
      <button class="btn" type="button" (click)="add()" [disabled]="busy() || influencerIds().length === 0">
        {{ (busy() ? 'Adding…' : 'Add to shortlist') | t }}
      </button>
    </div>
    @if (message()) {
      <p class="picker__message" [class.is-error]="failed()" role="status">{{ message() | t }}</p>
    }
  `,
})
export class ShortlistPicker implements OnInit {
  private readonly i18n = inject(I18n);
  private static nextId = 0;
  private readonly api = inject(Api);

  readonly influencerIds = input.required<number[]>();
  readonly added = output<void>();

  protected readonly fieldId = `shortlist-picker-${ShortlistPicker.nextId++}`;
  protected readonly newValue = NEW;
  protected readonly shortlists = signal<Shortlist[]>([]);
  protected readonly choice = signal('');
  protected readonly newName = signal('');
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly failed = signal(false);

  ngOnInit(): void {
    this.api.shortlists().subscribe({
      next: (shortlists) => this.shortlists.set(shortlists),
      error: () => this.shortlists.set([]),
    });
  }

  protected add(): void {
    const choice = this.choice();
    const ids = this.influencerIds();
    if (ids.length === 0) {
      return;
    }
    if (!choice) {
      this.say('Choose a shortlist first.', true);
      return;
    }

    if (choice !== NEW) {
      const shortlist = this.shortlists().find((s) => String(s.id) === choice);
      if (shortlist) {
        this.addTo(shortlist, ids);
      }
      return;
    }

    const name = this.newName().trim();
    if (!name) {
      this.say('Give the new shortlist a name.', true);
      return;
    }
    this.busy.set(true);
    this.api.createShortlist(name).subscribe({
      next: (shortlist) => {
        this.shortlists.update((list) => [shortlist, ...list]);
        this.choice.set(String(shortlist.id));
        this.newName.set('');
        this.addTo(shortlist, ids);
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.say(messageFrom(err, 'The shortlist could not be created.'), true);
      },
    });
  }

  private addTo(shortlist: Shortlist, ids: number[]): void {
    this.busy.set(true);
    this.api.addToShortlist(shortlist.id, ids).subscribe({
      next: (result) => {
        this.busy.set(false);
        this.say(
          result.added === 0
            ? this.i18n.t('Already in {name}.', { name: shortlist.name })
            : this.i18n.t('Added {count} to {name}.', { count: result.added, name: shortlist.name }) +
                (result.already_in_list ? ' ' + this.i18n.t('{count} already there.', { count: result.already_in_list }) : ''),
          false,
        );
        this.added.emit();
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.say(messageFrom(err, 'Could not add to the shortlist.'), true);
      },
    });
  }

  private say(text: string, failed: boolean): void {
    this.message.set(text);
    this.failed.set(failed);
  }
}
