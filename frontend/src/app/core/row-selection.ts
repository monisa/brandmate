import { computed, signal } from '@angular/core';

/** Which table rows are ticked. Backed by a signal, so templates update as boxes are ticked. */
export class RowSelection {
  private readonly selected = signal<ReadonlySet<number>>(new Set());

  readonly count = computed(() => this.selected().size);
  readonly ids = computed(() => [...this.selected()]);

  has(id: number): boolean {
    return this.selected().has(id);
  }

  toggle(id: number): void {
    this.selected.update((set) => {
      const next = new Set(set);
      if (!next.delete(id)) {
        next.add(id);
      }
      return next;
    });
  }

  /** True when `ids` is not empty and every one is ticked. */
  allSelected(ids: readonly number[]): boolean {
    const set = this.selected();
    return ids.length > 0 && ids.every((id) => set.has(id));
  }

  /** Ticks every id, or unticks them all when they were already all ticked. */
  toggleAll(ids: readonly number[]): void {
    this.selected.update((set) => {
      const next = new Set(set);
      if (ids.length > 0 && ids.every((id) => next.has(id))) {
        ids.forEach((id) => next.delete(id));
      } else {
        ids.forEach((id) => next.add(id));
      }
      return next;
    });
  }

  clear(): void {
    this.selected.set(new Set());
  }

  /** Forgets ticks for rows that are no longer in the list, for example after a reload or a filter change. */
  retain(ids: readonly number[]): void {
    const keep = new Set(ids);
    this.selected.update((set) => new Set([...set].filter((id) => keep.has(id))));
  }
}
