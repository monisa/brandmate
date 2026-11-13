import { describe, expect, it } from 'vitest';
import { cleanPasted } from './paste';

describe('cleanPasted', () => {
  it('trims spaces and line breaks around pasted text', () => {
    expect(cleanPasted('text', '  asha@example.com \n')).toBe('asha@example.com');
    expect(cleanPasted('text', 'two\nlines')).toBe('two lines');
  });

  it('leaves text that is already clean alone', () => {
    expect(cleanPasted('text', 'asha@example.com')).toBeNull();
    expect(cleanPasted('text', 'two words')).toBeNull();
  });

  it('turns formatted numbers into plain numbers', () => {
    expect(cleanPasted('number', '12,500')).toBe('12500');
    expect(cleanPasted('number', ' 1 200 ')).toBe('1200');
    expect(cleanPasted('number', '₹ 1,20,000')).toBe('120000');
    expect(cleanPasted('number', '4.5 %')).toBe('4.5');
    expect(cleanPasted('number', '1,234.50')).toBe('1234.50');
  });

  it('leaves plain numbers and non-numbers to the browser', () => {
    expect(cleanPasted('number', '12500')).toBeNull();
    expect(cleanPasted('number', 'twelve')).toBeNull();
    expect(cleanPasted('number', '12abc34')).toBeNull();
  });
});
