import { describe, expect, it } from 'vitest';
import { formatExactMoney, formatPublicMoney } from './money';

describe('money formatter', () => {
    it('formats exact owner money in units of one hundred million yen', () => {
        expect(formatExactMoney(62728)).toBe('62,728億円');
    });

    it.each([
        [0, '500億円未満', 'under_500'],
        [499, '500億円未満', 'under_500'],
        [500, '約500億円', '500'],
        [999, '約500億円', '500'],
        [1000, '約1,000億円', '1000'],
        [62728, '約62,000億円', '62000'],
    ])('formats public money %i without leaking the exact value', (money, display, bucket) => {
        expect(formatPublicMoney(money)).toEqual({ display, bucket });
    });
});
