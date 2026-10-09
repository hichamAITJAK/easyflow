import { Head } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';
import Heading from '@/components/heading';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

/**
 * Landing page of the Creatives module. The product briefs, review queue
 * and commissions screens come next; until then this is where admins and
 * creatives editors land so the role has a home from day one.
 */
export default function CreativesIndex() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Creatives')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Creatives')}
                    description={t(
                        'Product briefs, content requests and editor commissions.',
                    )}
                />

                <Empty className="border py-16">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <Clapperboard />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t('Creatives module is on its way')}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                'Editor accounts can already be created from the Team page. Briefs, the review queue and commissions arrive here next.',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            </div>
        </>
    );
}

CreativesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Creatives', href: '/creatives' },
    ],
};
