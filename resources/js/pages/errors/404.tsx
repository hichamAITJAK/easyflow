import ErrorPage from './error-page';

export default function Error404() {
    return (
        <ErrorPage
            status={404}
            title="Page not found"
            message="The page you're looking for doesn't exist or may have been moved."
        />
    );
}
