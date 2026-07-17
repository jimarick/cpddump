<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\Category;
use App\Models\FrameworkAttribute;
use App\Models\FrameworkDomain;
use App\Models\Profession;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    /**
     * Global activity types: [slug, name, color, icon]. Colors follow the
     * brand's four timeline category colors, grouped by kind of activity.
     */
    private const ACTIVITY_TYPES = [
        ['course', 'Course', '#f4590c', 'graduation-cap'],
        ['conference', 'Conference', '#f4590c', 'presentation'],
        ['exam', 'Examination', '#f4590c', 'file-check'],
        ['certificate', 'Certificate', '#f4590c', 'award'],
        ['meeting', 'Meeting', '#3f8fd2', 'users'],
        ['mdt', 'MDT', '#3f8fd2', 'stethoscope'],
        ['committee', 'Committee membership', '#3f8fd2', 'landmark'],
        ['leadership', 'Leadership', '#3f8fd2', 'compass'],
        ['employment', 'Employment', '#3f8fd2', 'briefcase'],
        ['teaching', 'Teaching', '#2f9e64', 'school'],
        ['presentation', 'Presentation', '#2f9e64', 'mic'],
        ['publication', 'Publication', '#2f9e64', 'book-open'],
        ['research', 'Research', '#2f9e64', 'flask-conical'],
        ['audit', 'Audit', '#9a6fd0', 'clipboard-check'],
        ['quality_improvement', 'Quality improvement', '#9a6fd0', 'trending-up'],
        ['significant_event', 'Significant event', '#9a6fd0', 'alert-triangle'],
        ['complaint', 'Complaint', '#9a6fd0', 'message-square-warning'],
        ['compliment', 'Compliment', '#9a6fd0', 'heart'],
        ['reflection', 'Reflection', '#9a6fd0', 'pen-line'],
        ['award', 'Award', '#f4590c', 'trophy'],
        ['voice_note', 'Voice note', '#3f8fd2', 'mic-vocal'],
        ['email', 'Email', '#3f8fd2', 'mail'],
    ];

    /** GMC supporting information categories (the 9). */
    private const CATEGORIES = [
        ['cpd', 'Continuing professional development'],
        ['quality_improvement', 'Quality improvement activity'],
        ['significant_events', 'Significant events'],
        ['colleague_feedback', 'Colleague feedback'],
        ['patient_feedback', 'Patient feedback'],
        ['complaints_compliments', 'Complaints and compliments'],
        ['job_plan', 'Job plan'],
        ['academia_research', 'Academia and research'],
        ['education_training', 'Education/training roles'],
    ];

    /** GMC Good Medical Practice domains and attributes. */
    private const FRAMEWORK = [
        'D1' => [
            'name' => 'Knowledge, skills and development',
            'attributes' => [
                '1.1' => 'Being competent',
                '1.2' => 'Providing good clinical care',
                '1.3' => 'Offering remote consultations',
                '1.4' => 'Considering research opportunities',
                '1.5' => 'Maintaining, developing and improving your performance',
                '1.6' => 'Managing resources effectively and sustainably',
            ],
        ],
        'D2' => [
            'name' => 'Patients, partnership and communication',
            'attributes' => [
                '2.1' => 'Treating patients fairly and respecting their rights',
                '2.2' => 'Treating patients with kindness, courtesy and respect',
                '2.3' => 'Supporting patients to make decisions about treatment and care',
                '2.4' => 'Sharing information with patients',
                '2.5' => 'Communicating with those close to a patient',
                '2.6' => 'Caring for the whole patient',
                '2.7' => 'Safeguarding children and adults who are at risk of harm',
                '2.8' => 'Helping in emergencies',
                '2.9' => 'Making sure patients who pose a risk of harm to others can access appropriate care',
                '2.10' => 'Being open if things go wrong',
            ],
        ],
        'D3' => [
            'name' => 'Colleagues, culture and safety',
            'attributes' => [
                '3.1' => 'Treating colleagues with kindness, courtesy and respect',
                '3.2' => 'Contributing to a positive working and training environment',
                '3.3' => 'Demonstrating leadership behaviours',
                '3.4' => 'Contributing to continuity of care',
                '3.5' => 'Delegating safely and appropriately',
                '3.6' => 'Recording your work clearly, accurately and legibly',
                '3.7' => 'Keeping patients safe',
                '3.8' => 'Responding to safety risks',
                '3.9' => 'Managing risks posed by your health',
            ],
        ],
        'D4' => [
            'name' => 'Trust and professionalism',
            'attributes' => [
                '4.1' => 'Acting with honesty and integrity',
                '4.2' => 'Acting with honesty and integrity in research',
                '4.3' => 'Maintaining professional boundaries',
                '4.4' => 'Communicating as a medical professional',
                '4.5' => 'Managing conflicts of interest',
                '4.6' => 'Cooperating with legal and regulatory requirements',
            ],
        ],
    ];

    /** UK doctor reflection prompts (MAG-style supporting information questions). */
    private const REFLECTION_PROMPTS = [
        [
            'key' => 'why_selected',
            'label' => 'Why was this selected?',
            'question' => 'Why was this item selected as Supporting Information? Does it meet an identified PDP need? Was this a significant work related event where I was able to reflect/learn? Does this help me demonstrate that I am meeting minimum standards for Good Medical Practice?',
        ],
        [
            'key' => 'learning_need',
            'label' => 'What was learned?',
            'question' => 'If this was formal learning, what was the learning need or objective addressed? Describe how the activity contributed to the development of your knowledge, skills or attitudes.',
        ],
        [
            'key' => 'practice_change',
            'label' => 'What will change?',
            'question' => 'Describe how this Supporting Information will change the way you work. How have your knowledge, skills and attitudes changed? How might this improve patient care or safety? How will your current practice change as a consequence?',
        ],
    ];

    public function run(): void
    {
        foreach (self::ACTIVITY_TYPES as $i => [$slug, $name, $color, $icon]) {
            ActivityType::updateOrCreate(
                ['profession_id' => null, 'slug' => $slug],
                ['name' => $name, 'color' => $color, 'icon' => $icon, 'sort_order' => $i],
            );
        }

        $profession = Profession::updateOrCreate(
            ['slug' => 'uk-doctor'],
            [
                'name' => 'UK Doctor',
                'is_active' => true,
                'settings' => [
                    'appraisal_window' => ['start_month' => 4, 'start_day' => 1],
                    'points_heuristic' => 'Roughly one CPD point per hour of learning activity.',
                    'reflection_prompts' => self::REFLECTION_PROMPTS,
                    'framework_name' => 'GMC Good Medical Practice',
                ],
            ],
        );

        foreach (self::CATEGORIES as $i => [$slug, $name]) {
            Category::updateOrCreate(
                ['profession_id' => $profession->id, 'slug' => $slug],
                ['name' => $name, 'sort_order' => $i],
            );
        }

        foreach (array_keys(self::FRAMEWORK) as $i => $code) {
            $domainData = self::FRAMEWORK[$code];

            $domain = FrameworkDomain::updateOrCreate(
                ['profession_id' => $profession->id, 'code' => $code],
                ['name' => $domainData['name'], 'sort_order' => $i],
            );

            $j = 0;
            foreach ($domainData['attributes'] as $attrCode => $attrName) {
                FrameworkAttribute::updateOrCreate(
                    ['framework_domain_id' => $domain->id, 'code' => $attrCode],
                    ['name' => $attrName, 'sort_order' => $j++],
                );
            }
        }
    }
}
