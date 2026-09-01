import ErrorPage from './error-page';

export default function Error403() {
    return (
        <ErrorPage
            status={403}
            title="Access denied"
            message="You don't have permission to view this page."
        />
    );
}
