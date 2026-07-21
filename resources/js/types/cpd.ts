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
    /** File deliberately not kept — row is an honest metadata stub. */
    purged?: boolean;
    /** On a merged entry: which absorbed source this file belongs to. */
    from?: string;
}

export interface PiiFlag {
    type: string;
    /** Redacted to type+severity once the item is resolved. */
    excerpt?: string;
    severity: 'low' | 'medium' | 'high';
    detected_by?: 'scanner';
}

/**
 * One nugget (learned) or action (to chase) — id-addressed everywhere.
 * A type alias (not an interface) so it satisfies Inertia's implicit
 * index-signature check when submitted in a form payload.
 */
export type Takeaway = {
    id: string;
    text: string;
    done: boolean;
};

export interface AiAnalysis {
    title: string;
    activity_type_slug: string;
    starts_on: string | null;
    ends_on: string | null;
    organisation: string | null;
    cpd_points: number;
    summary: string;
    /** The user's own words from the evidence, verbatim — never third-party text. */
    user_notes?: string | null;
    /** Pre-rename analyses only — superseded by nuggets. */
    suggested_learning_points?: string[];
    nuggets?: Takeaway[];
    actions?: Takeaway[];
    /** Answers are null when the user's own words held no reflection. */
    reflection_draft: Record<string, string | null>;
    /** Where a pre-filled reflection came from, quoting the user's words. */
    reflection_source?: string | null;
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
    possible_related_inbox_item_ids?: number[];
    possible_related_activity_ids?: number[];
    related_reason?: string | null;
    pii_resolved?: 'removed' | 'affirmed';
    pii_resolved_at?: string;
}

export interface MergeSuggestionRef {
    kind: 'activity' | 'inbox';
    id: number;
    title: string;
    merged: boolean;
    reason: 'recurrence' | 'duplicate' | 'related';
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
    /** True when flagged content still exists in a file or the user's own text. */
    pii_gate: boolean;
    /** Titled, current-period matches worth merging into — never enforced. */
    merge_suggestions?: MergeSuggestionRef[];
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
        expectations: ExpectationProgress[];
    };
}

export interface ExpectationProgress {
    id: number;
    title: string;
    expected: number;
    captured: number;
}

export interface RecurrenceData {
    id: number;
    kind: 'scheduled' | 'expectation';
    title: string;
    type: string | null;
    frequency: 'weekly' | 'fortnightly' | 'monthly' | null;
    expected_per_year: number | null;
    reminder: 'same_day' | 'weekly' | 'none';
    is_active: boolean;
    captured: number | null;
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
    nuggets: Takeaway[];
    actions: Takeaway[];
    source_notes: string | null;
    type: { slug: string; name: string; color: string; icon: string };
    categories: { slug: string; name: string }[];
    domains: { code: string; name: string }[];
    attribute_codes: string[];
    projects: { id: number; title: string }[];
    attachments: AttachmentRef[];
    /** Non-empty when this is a merged entry hiding absorbed sources. */
    merged_from?: MergedSourceRef[];
    /** Was once inside a merged entry, later split back out. */
    formerly_merged?: boolean;
    /** Promoted straight from the inbox during a merge — never individually reviewed. */
    merge_unreviewed?: boolean;
}

export interface MergedSourceRef {
    id: number;
    title: string;
    starts_on: string | null;
    cpd_points: number;
}

export interface MergeSeed {
    activity_ids: number[];
    inbox_item_ids: number[];
    into_activity_id?: number | null;
}

export interface MergeSourceSummary {
    kind: 'activity' | 'inbox_item';
    id: number;
    title: string;
    starts_on: string | null;
    cpd_points: number;
    source: string | null;
    pii_gate: boolean;
    is_target: boolean;
    pii_flags?: { type: string; severity: string }[];
    attachments: {
        id: number;
        name: string;
        purged: boolean;
        keepable: boolean;
    }[];
}

export interface MergePreview {
    defaults: {
        title: string;
        activity_type_slug: string | null;
        starts_on: string | null;
        ends_on: string | null;
        cpd_points: number;
        points_breakdown: number[];
        organisation: string | null;
        details: string;
        reflection: Record<string, string>;
        category_slugs: string[];
        domain_codes: string[];
        attribute_codes: string[];
        project_ids: number[];
    };
    sources: MergeSourceSummary[];
    blocking: { pii_item_ids: number[] };
    retention: 'ask' | 'always' | 'never';
}

/** The AI-drafted combined entry, applied over the deterministic defaults. */
export interface MergeDraft {
    title: string | null;
    activity_type_slug: string | null;
    organisation: string | null;
    details: string | null;
    reflection: Record<string, string>;
}

export interface MergeCandidates {
    activities: {
        id: number;
        title: string;
        starts_on: string | null;
        cpd_points: number;
        type: { name: string; color: string };
        merged: boolean;
    }[];
    inbox_items: {
        id: number;
        title: string;
        starts_on: string | null;
        cpd_points: number;
        source_label: string;
    }[];
}
