import { TestBed } from '@angular/core/testing';
import { MetricPipe } from './metric.pipe';

describe('MetricPipe', () => {
  const pipe = TestBed.runInInjectionContext(() => new MetricPipe());

  it('says the data is unavailable instead of showing zero', () => {
    expect(pipe.transform(null)).toBe('Data unavailable');
    expect(pipe.transform(undefined, 'percent')).toBe('Data unavailable');
  });

  it('keeps a real zero', () => {
    expect(pipe.transform(0)).toBe('0');
  });

  it('formats counts and percentages', () => {
    expect(pipe.transform(1_250_000)).toBe('1.3M');
    expect(pipe.transform(48_200)).toBe('48.2K');
    expect(pipe.transform(1_204_331, 'full')).toBe('1,204,331');
    expect(pipe.transform(4.5, 'percent')).toBe('4.5%');
    expect(pipe.transform(3, 'percent')).toBe('3%');
  });
});
