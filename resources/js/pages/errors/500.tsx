import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error500() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={500}
            title={t('Something went wrong')}
            message={t('An unexpected error occurred on our end. Please try again shortly.')}
        />
    );
}
