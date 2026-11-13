import { Component, inject } from '@angular/core';
import { I18n, Lang } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';

/** Language menu. Each language is written in its own script so it can be found by anyone. */
@Component({
  selector: 'app-language-switcher',
  imports: [TPipe],
  template: `
    <label class="visually-hidden" [for]="id">{{ 'Language' | t }}</label>
    <select [id]="id" class="language" (change)="change($any($event.target).value)">
      @for (language of i18n.languages; track language.code) {
        <option [value]="language.code" [selected]="language.code === i18n.lang()" [attr.lang]="language.code">{{ language.name }}</option>
      }
    </select>
  `,
})
export class LanguageSwitcher {
  private static next = 0;
  protected readonly i18n = inject(I18n);
  protected readonly id = `language-${LanguageSwitcher.next++}`;

  protected change(code: string): void {
    void this.i18n.use(code as Lang);
  }
}
