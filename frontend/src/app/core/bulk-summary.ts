import { BulkDeleteResult } from './models';
import { I18n } from './i18n/i18n';

/** "Deleted: 3 users, 1 company." plus a note about rows that were refused. */
export function bulkSummary(i18n: I18n, result: BulkDeleteResult): string {
  const count = (n: number, one: string, many: string) => i18n.t(n === 1 ? one : many, { count: n });
  const parts: string[] = [];
  if (result.users) {
    parts.push(count(result.users, '{count} user', '{count} users'));
  }
  if (result.companies) {
    parts.push(count(result.companies, '{count} company', '{count} companies'));
  }
  if (result.creators) {
    parts.push(count(result.creators, '{count} creator', '{count} creators'));
  }

  let text = parts.length ? i18n.t('Deleted: {list}.', { list: parts.join(', ') }) : i18n.t('Nothing was deleted.');
  if (result.skipped) {
    text += ' ' + i18n.t('{count} could not be deleted: super admins, your own account, or rows that no longer exist.', { count: result.skipped });
  }
  return text;
}
