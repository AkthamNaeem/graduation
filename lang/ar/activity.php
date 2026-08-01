<?php

return [
    'retrieved' => 'تم جلب النشاط بنجاح.',
    'role_not_allowed' => 'صفحة النشاط متاحة للباحثين عن عمل فقط.',
    'types' => [
        'test' => 'اختبار',
        'interview' => 'مقابلة',
        'information_request' => 'طلب معلومات',
        'application_status' => 'تحديث حالة الطلب',
        'application_reminder' => 'تذكير بالطلب',
        'final_decision' => 'قرار نهائي',
    ],
    'actions' => [
        'start_test' => 'بدء الاختبار',
        'continue_test' => 'متابعة الاختبار',
        'submit_information' => 'إرسال المعلومات',
        'confirm_interview' => 'تأكيد المقابلة',
        'view_interview' => 'عرض المقابلة',
        'view_application' => 'عرض الطلب',
        'view_test_result' => 'عرض نتيجة الاختبار',
        'none' => 'لا يوجد إجراء',
    ],
    'items' => [
        'test_pending_title' => 'اختبار بانتظار الإكمال',
        'test_continue_title' => 'اختبار قيد التنفيذ',
        'test_description' => 'أكمل الاختبار قبل الموعد النهائي.',
        'interview_title' => 'مقابلة قادمة',
        'information_request_title' => 'معلومات إضافية مطلوبة',
    ],
    'validation' => [
        'group' => 'مجموعة النشاط المحددة غير صالحة.',
        'type' => 'نوع النشاط المحدد غير صالح.',
        'sort_by' => 'ترتيب النشاط المحدد غير صالح.',
        'sort_direction' => 'اتجاه الترتيب المحدد غير صالح.',
        'timezone' => 'يجب أن تكون المنطقة الزمنية صالحة وفق IANA.',
        'date_range' => 'يجب أن يكون تاريخ النهاية مساويًا لتاريخ البداية أو بعده.',
    ],
];
