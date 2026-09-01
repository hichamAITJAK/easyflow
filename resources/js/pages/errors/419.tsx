import ErrorPage from './error-page';

export default function Error419() {
    return (
        <ErrorPage
            status={419}
            title="Session expired"
            message="Your session has expired. Please refresh the page and try again."
        />
    );
}
