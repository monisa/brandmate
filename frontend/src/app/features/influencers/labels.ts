import { Influencer, Platform } from '../../core/models';

export const PLATFORM_LABELS: Record<Platform, string> = {
  instagram: 'Instagram',
  youtube: 'YouTube',
  facebook: 'Facebook',
};

const regions = (() => {
  try {
    return new Intl.DisplayNames(['en'], { type: 'region' });
  } catch {
    return null;
  }
})();

export function countryName(code: string | null): string {
  if (!code) {
    return '';
  }
  try {
    return regions?.of(code) ?? code;
  } catch {
    return code;
  }
}

export function locationOf(influencer: Pick<Influencer, 'city' | 'state' | 'country'>): string {
  return [influencer.city, countryName(influencer.country)].filter(Boolean).join(', ');
}

export function initialsOf(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('');
}

/** Soft cover gradient for a creator card, picked by category so a category always looks the same. */
const COVERS: Record<string, string> = {
  Fashion: 'linear-gradient(120deg, #fbcfe8, #f9a8d4 45%, #c084fc)',
  Beauty: 'linear-gradient(120deg, #fecdd3, #fda4af 50%, #f472b6)',
  Fitness: 'linear-gradient(120deg, #bbf7d0, #4ade80 55%, #0d9488)',
  Food: 'linear-gradient(120deg, #fde68a, #fbbf24 50%, #f97316)',
  Travel: 'linear-gradient(120deg, #bae6fd, #38bdf8 50%, #0ea5e9)',
  Technology: 'linear-gradient(120deg, #c7d2fe, #818cf8 50%, #4f46e5)',
  Gaming: 'linear-gradient(120deg, #ddd6fe, #a78bfa 50%, #7c3aed)',
  Parenting: 'linear-gradient(120deg, #fed7aa, #fdba74 50%, #fb7185)',
  Finance: 'linear-gradient(120deg, #a7f3d0, #34d399 50%, #047857)',
  Education: 'linear-gradient(120deg, #bfdbfe, #60a5fa 50%, #2563eb)',
  Comedy: 'linear-gradient(120deg, #fef08a, #facc15 50%, #fb923c)',
  Music: 'linear-gradient(120deg, #f5d0fe, #e879f9 50%, #a21caf)',
};

export function coverOf(category: string | null): string {
  return (category && COVERS[category]) || 'linear-gradient(120deg, #e0e7ff, #c7d2fe 50%, #a5b4fc)';
}

/** How the stored data_source codes read to a person. */
export function sourceLabel(source: string): string {
  const labels: Record<string, string> = {
    sample: 'Sample data (fictional)',
    self_reported: 'Entered by the creator, not verified',
    official_api: 'Official platform API',
    authorized_creator: 'Shared by the creator',
    derived: 'Calculated from other data',
  };
  return labels[source] ?? source;
}
