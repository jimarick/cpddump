export interface ActivityTypeRef {
    id: number;
    slug: string;
    name: string;
    color: string;
    icon: string;
}

export interface CategoryRef {
    id: number;
    slug: string;
    name: string;
}

export interface FrameworkAttributeRef {
    id: number;
    framework_domain_id: number;
    code: string;
    name: string;
}

export interface FrameworkDomainRef {
    id: number;
    code: string;
    name: string;
    framework_attributes: FrameworkAttributeRef[];
}

export interface ReflectionPrompt {
    key: string;
    label: string;
    question: string;
}

export interface ProjectRef {
    id: number;
    title: string;
    kind?: string;
}

export interface ReferenceData {
    activityTypes: ActivityTypeRef[];
    categories: CategoryRef[];
    domains: FrameworkDomainRef[];
    reflectionPrompts: ReflectionPrompt[];
    projects: ProjectRef[];
}

export interface AttachmentRef {
    id: number;
    name: string;
    mime_type: string;
}

export interface PiiFlag {
    type: string;
    excerpt: string;
    severity: 'low' | 'medium' | 'high';
}

export interface AiAnalysis {
    title: string;
    activity_type_slug: string;
    starts_on: string | null;
    ends_on: string | null;
    organisation: string | null;
    cpd_points: number;
    summary: string;
    suggested_learning_points: string[];
    reflection_draft: Record<string, string>;
    category_slugs: string[];
    domain_codes: string[];
    attribute_codes: string[];
    suggested_project_ids: number[];
    possible_duplicate_activity_ids: number[];
    confidence: number;
    pii_flags: PiiFlag[];
    missing_evidence: string[];
}

export interface AiWarnings {
    pii_flags?: PiiFlag[];
    missing_evidence?: string[];
    possible_duplicate_activity_ids?: number[];
    possible_duplicate_inbox_item_ids?: number[];
}

export type InboxItemStatus =
    'pending' | 'analysing' | 'ready' | 'approved' | 'dismissed' | 'failed';

export interface InboxItemData {
    id: number;
    source: string;
    source_label: string;
    status: InboxItemStatus;
    raw_payload: Record<string, unknown>;
    ai_analysis: AiAnalysis | null;
    ai_warnings: AiWarnings | null;
    failure_reason: string | null;
    created_at: string;
    attachments: AttachmentRef[];
}

export interface GapEntry {
    slug?: string;
    code?: string;
    name: string;
    count: number;
}

export interface InboxStats {
    activities: number;
    points: number;
    awaiting: number;
    gaps: {
        categories: GapEntry[];
        domains: GapEntry[];
    };
}

export interface PeriodData {
    id: number;
    label: string;
    starts_on: string;
    ends_on: string;
}

export interface ActivityData {
    id: number;
    title: string;
    starts_on: string | null;
    ends_on: string | null;
    cpd_points: number;
    organisation: string | null;
    details: string | null;
    reflection: Record<string, string>;
    type: { slug: string; name: string; color: string; icon: string };
    categories: { slug: string; name: string }[];
    domains: { code: string; name: string }[];
    attribute_codes: string[];
    projects: { id: number; title: string }[];
    attachments: AttachmentRef[];
}
