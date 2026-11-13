import { Injectable, computed, signal } from '@angular/core';

export const COMPARE_LIMIT = 5;

/** Influencers ticked for comparison. Survives moving between pages. */
@Injectable({ providedIn: 'root' })
export class CompareSelection {
  private readonly selected = signal<number[]>([]);

  readonly ids = this.selected.asReadonly();
  readonly count = computed(() => this.selected().length);
  readonly full = computed(() => this.selected().length >= COMPARE_LIMIT);

  has(id: number): boolean {
    return this.selected().includes(id);
  }

  toggle(id: number): void {
    if (this.has(id)) {
      this.remove(id);
    } else if (!this.full()) {
      this.selected.update((ids) => [...ids, id]);
    }
  }

  remove(id: number): void {
    this.selected.update((ids) => ids.filter((other) => other !== id));
  }

  set(ids: number[]): void {
    this.selected.set(ids.slice(0, COMPARE_LIMIT));
  }

  clear(): void {
    this.selected.set([]);
  }
}
