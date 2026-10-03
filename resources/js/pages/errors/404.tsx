import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error404() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={404}
            title={t('Page not found')}
            message={t("The page you're looking for doesn't exist or may have been moved.")}
        />
    );
}
