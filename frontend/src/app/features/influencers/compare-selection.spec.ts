import { COMPARE_LIMIT, CompareSelection } from './compare-selection';

describe('CompareSelection', () => {
  it('adds, removes and stops at the limit', () => {
    const selection = new CompareSelection();

    for (let id = 1; id <= COMPARE_LIMIT + 2; id++) {
      selection.toggle(id);
    }
    expect(selection.ids()).toEqual([1, 2, 3, 4, 5]);
    expect(selection.full()).toBe(true);

    selection.toggle(2);
    expect(selection.ids()).toEqual([1, 3, 4, 5]);
    expect(selection.full()).toBe(false);
  });
});
